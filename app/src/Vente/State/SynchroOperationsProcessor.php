<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Caisse\Entity\SessionCaisse;
use App\Vente\Entity\Vente;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Nf525\SignataireOperation;
use App\Vente\Service\AjoutLigneHandler;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PaiementHandler;
use App\Vente\Service\ValiderVenteService;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Remontée d'un lot d'opérations hors-ligne (POST /synchro/operations, RG-M2-08 / CA-16). Rejeu
 * chronologique par séquence locale, anti-doublon idempotent (une clé déjà remontée = no-op),
 * vérification de la chaîne serveur avant ancrage, quarantaine sur incohérence — sans casser la
 * chaîne. Chaque vente remontée est composée et validée côté serveur (donc scellée) en conservant sa
 * clé d'idempotence et ses identifiants client (« une seule remontée par session »). Corps :
 *   { "session": iri|uuid, "operations": [
 *       { "cleIdempotence": uuid, "sequenceLocale": int, "id"?: uuid,
 *         "numeroLocal"?: "HL-…" (ticket provisoire), "horodatageLocal"?: iso8601 (heure du comptoir),
 *         "lignes": [{"produit":…, "typeTarif":…, "quantite":…}],
 *         "paiements": [{"moyen":…, "montant":…}] } ] }
 *
 * Réponse : inseres, doublons, quarantaine, anomalies, `correspondances` (clé → numéro provisoire →
 * numéro définitif, doublons compris) et `reportees` (non traitées, à renvoyer). Les remontées d'une
 * même session sont sérialisées par un verrou en base (#338).
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class SynchroOperationsProcessor implements ProcessorInterface
{
    /** Attente du verrou de session : au-delà, 409 et le poste réessaie. */
    private const ATTENTE_VERROU_SECONDES = 10;

    /** Dérive d'horloge tolérée entre le poste et le serveur, pour une heure « dans le futur ». */
    private const DERIVE_HORLOGE_SECONDES = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GenerateurNumero $generateur,
        private readonly AjoutLigneHandler $ajout,
        private readonly PaiementHandler $paiement,
        private readonly ValiderVenteService $valider,
        private readonly ScellementHandler $scellement,
        private readonly SignataireOperation $signataire,
        private readonly ContexteEtablissement $contexte,
        private readonly JournalAudit $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();
        $session = $this->resoudreSession($corps['session'] ?? null);
        if (!$session->estOuverte()) {
            throw new ConflictHttpException('Session non ouverte : ancrage impossible.');
        }

        // Une entrée qui n'est pas un objet faisait lever `usort` (TypeError, donc 500) : le lot entier
        // échouait sur une ligne illisible. Elle est écartée et rendue, le reste du lot passe.
        $brutes = \is_array($corps['operations'] ?? null) ? $corps['operations'] : [];
        $operations = array_values(array_filter($brutes, '\is_array'));
        $illisibles = \count($brutes) - \count($operations);

        // Rejeu chronologique par séquence locale.
        usort($operations, static fn (array $a, array $b): int => ((int) ($a['sequenceLocale'] ?? 0)) <=> ((int) ($b['sequenceLocale'] ?? 0)));

        // Trou de séquence dans le lot → rejet (alerte de contrôle), pas d'insertion partielle.
        $this->verifierContinuite($operations);

        // ⚠ DEUX REMONTÉES DU MÊME LOT EN MÊME TEMPS FONT DEUX VENTES SANS CE VERROU (#338).
        //
        // L'anti-doublon est un `findOneBy` suivi d'une insertion : deux requêtes simultanées (deux
        // onglets, ou une relance du poste pendant que la première réponse est encore en route) lisent
        // toutes deux « absente », et la seconde heurte la contrainte d'unicité. Doctrine FERME alors le
        // gestionnaire d'entités : toutes les opérations suivantes du lot échouaient, et partaient en
        // quarantaine avec un message qui ne disait pas leur vraie cause. Le verrou est pris par
        // SESSION, en base (seul point commun à tous les processus php-fpm), et relâché en sortie.
        $verrou = 'fluvia_synchro_' . $session->getId()->toRfc4122();
        $connexion = $this->em->getConnection();
        if ((int) $connexion->fetchOne('SELECT GET_LOCK(?, ?)', [$verrou, self::ATTENTE_VERROU_SECONDES]) !== 1) {
            throw new ConflictHttpException('Une remontée est déjà en cours pour cette session : réessayez dans un instant.');
        }

        try {
            return $this->rejouer($session, $operations, $illisibles);
        } finally {
            $connexion->fetchOne('SELECT RELEASE_LOCK(?)', [$verrou]);
        }
    }

    /**
     * @param list<array<string, mixed>> $operations triées par séquence locale
     */
    private function rejouer(SessionCaisse $session, array $operations, int $illisibles): JsonResponse
    {
        // Vérification de la chaîne serveur avant ancrage (CA-15/16).
        $pdv = $session->getPointDeVente();
        if ($pdv !== null) {
            $rapport = $this->signataire->verifieChaine($this->scellement->chaine($pdv));
            if (!$rapport->intacte) {
                throw new ConflictHttpException('Chaîne serveur rompue : ancrage refusé (alerte de contrôle NF525).');
            }
        }

        $inseres = [];
        $doublons = [];
        $quarantaine = [];
        // Ventes ENREGISTRÉES malgré un produit que la caisse n'aurait pas dû vendre : rendues ici et
        // tracées au journal d'audit, pour qu'un responsable les voie sans que la recette se perde.
        $anomalies = [];
        // Le ticket provisoire remis au client porte le numéro du poste ; la vente reçoit ici son
        // numéro définitif. Le poste a besoin des deux pour dire au caissier ce qu'est devenu chacun.
        $correspondances = [];
        // Opérations NON TRAITÉES : le poste les garde et les renverra. Ce n'est pas une quarantaine.
        $reportees = [];
        for ($i = 0; $i < $illisibles; ++$i) {
            $quarantaine[] = ['raison' => 'opération illisible (objet attendu)'];
        }

        foreach ($operations as $rang => $op) {
            $cle = $this->uuid($op['cleIdempotence'] ?? null);
            if ($cle === null) {
                $quarantaine[] = ['raison' => 'clé d\'idempotence manquante'];
                continue;
            }
            $existante = $this->em->getRepository(Vente::class)->findOneBy(['cleIdempotence' => $cle]);
            // ⚠ LA CLÉ EST UNIQUE SUR TOUTE LA PLATEFORME, LA RÉPONSE NE L'EST PAS. Rendre le numéro et
            // l'identifiant d'une vente d'un AUTRE établissement à qui présente sa clé serait une fuite
            // (D8) : la clé est réputée secrète, mais un secret qui ouvre la porte d'un autre client
            // n'en est pas un. Une collision hors du site est refusée sans rien dire de l'autre vente.
            if ($existante instanceof Vente && (string) $existante->getEtablissement()?->getId() !== (string) $session->getEtablissement()?->getId()) {
                $quarantaine[] = ['cle' => (string) $cle, 'raison' => 'clé d\'idempotence déjà utilisée'];
                continue;
            }
            if ($existante instanceof Vente) {
                $doublons[] = (string) $cle; // rejouer = no-op.
                // Le poste qui n'a pas reçu la première réponse (coupure pendant le retour) doit
                // quand même apprendre le numéro définitif : sinon il ne sait jamais le relier.
                $correspondances[] = $this->correspondance($cle, $op, $existante);
                continue;
            }

            try {
                [$vente, $ecarts] = $this->composer($session, $cle, $op);
                $this->em->flush();
                $inseres[] = (string) $vente->getId();
                $correspondances[] = $this->correspondance($cle, $op, $vente);
                foreach ($ecarts as $ecart) {
                    $anomalies[] = ['cle' => (string) $cle, 'vente' => (string) $vente->getId(), 'raison' => $ecart];
                }
            } catch (\Throwable $e) {
                // ⚠ UN GESTIONNAIRE FERMÉ NE SE ROUVRE PAS PAR `clear()`. Après une erreur de base, la
                // suite du lot aurait échoué ligne à ligne, en quarantaine, pour une cause qui n'est
                // pas la sienne. On s'arrête : ce qui reste est REPORTÉ, et le poste le renverra.
                if (!$this->em->isOpen()) {
                    foreach (\array_slice($operations, $rang) as $restante) {
                        $reportees[] = (string) ($restante['cleIdempotence'] ?? '');
                    }
                    break;
                }
                $this->em->clear();
                $session = $this->resoudreSession((string) $session->getId());
                $quarantaine[] = ['cle' => (string) $cle, 'raison' => $e->getMessage()];
            }
        }

        return new JsonResponse([
            'session' => $session->getNumero(),
            'recus' => \count($operations) + $illisibles,
            'inseres' => $inseres,
            'doublons' => $doublons,
            'quarantaine' => $quarantaine,
            'anomalies' => $anomalies,
            'correspondances' => $correspondances,
            'reportees' => $reportees,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $op
     *
     * @return array{cle: string, numeroLocal: ?string, vente: string, numero: string}
     */
    private function correspondance(Uuid $cle, array $op, Vente $vente): array
    {
        return [
            'cle' => (string) $cle,
            'numeroLocal' => $this->numeroLocal($op),
            'vente' => (string) $vente->getId(),
            'numero' => $vente->getNumero(),
        ];
    }

    /**
     * Le numéro du ticket provisoire, tel que le poste l'a imprimé. Borné et filtré : il est écrit au
     * journal d'audit et rendu à l'écran, il ne doit transporter ni balise ni texte arbitraire.
     *
     * @param array<string, mixed> $op
     */
    private function numeroLocal(array $op): ?string
    {
        $brut = $op['numeroLocal'] ?? null;
        if (!\is_string($brut) || preg_match('/^[A-Z0-9-]{1,40}$/', $brut) !== 1) {
            return null;
        }

        return $brut;
    }

    /**
     * L'HEURE DE LA VENTE EST CELLE DU COMPTOIR, PAS CELLE DE LA REMONTÉE (#338).
     *
     * Sans elle, trois ventes faites à 10 h pendant une coupure étaient datées de 14 h, heure du retour
     * du réseau — et scellées ainsi : la date figée au journal NF525 aurait été fausse pour toujours.
     *
     * ⚠ MAIS UNE HEURE FOURNIE PAR LE POSTE EST UNE DÉCLARATION, PAS UN FAIT. Elle n'est retenue que
     * dans la vie de la session (après son ouverture, et pas dans le futur au-delà d'une dérive
     * d'horloge tolérée). Hors de ces bornes, la vente n'est PAS refusée — l'argent est dans le tiroir
     * — : elle prend l'heure du serveur, et l'écart est rendu et tracé comme une anomalie.
     *
     * @param array<string, mixed> $op
     *
     * @return array{0: \DateTimeImmutable|null, 1: string|null} l'heure retenue (null = celle du serveur), et l'anomalie
     */
    private function horodatageLocal(SessionCaisse $session, array $op): array
    {
        $brut = $op['horodatageLocal'] ?? null;
        if ($brut === null) {
            return [null, null];
        }
        // ISO 8601 avec fuseau EXPLICITE seulement : une heure sans fuseau serait lue dans celui du
        // serveur, et décalée d'une ou deux heures sans que rien ne le signale.
        $heure = null;
        if (\is_string($brut) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $brut) === 1) {
            try {
                $heure = new \DateTimeImmutable($brut);
            } catch (\Exception) {
                $heure = null;
            }
        }
        if (!$heure instanceof \DateTimeImmutable) {
            return [null, 'horodatage local illisible : heure du serveur retenue'];
        }
        // Ramenée au fuseau du processus : Doctrine écrit l'heure murale de l'objet sans conversion, et
        // toutes les autres dates de vente sont créées dans ce fuseau-là.
        $heure = $heure->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $maintenant = new \DateTimeImmutable();
        if ($heure < $session->getOuvertureLe() || $heure > $maintenant->modify(sprintf('+%d seconds', self::DERIVE_HORLOGE_SECONDES))) {
            return [null, sprintf('horodatage local hors de la session (%s) : heure du serveur retenue', $heure->format(\DATE_ATOM))];
        }

        return [$heure, null];
    }

    /**
     * @param array<string, mixed> $op
     *
     * @return array{0: Vente, 1: list<string>} la vente, et les motifs d'invendabilité tracés
     */
    private function composer(SessionCaisse $session, Uuid $cle, array $op): array
    {
        $vente = new Vente();
        if (($id = $this->uuid($op['id'] ?? null)) !== null) {
            $vente->setId($id);
        }
        $vente->setCleIdempotence($cle)
            ->setSession($session)
            ->setEtablissement($session->getEtablissement())
            ->setNumero($this->generateur->numeroVente($session))
            ->setOrigineHorsLigne(true);
        // Avant la validation : c'est elle qui scelle, et le scellement fige la date dans la chaîne.
        [$heure, $ecartHeure] = $this->horodatageLocal($session, $op);
        if ($heure !== null) {
            $vente->setDate($heure);
        }
        $this->em->persist($vente);

        // La vente a déjà eu lieu : un produit devenu invendable ne la fait pas refuser (voir
        // `AjoutLigneHandler::replayOfflineLine`). L'écart est tracé plus bas, dans le même flush.
        $ecarts = $ecartHeure !== null ? [$ecartHeure] : [];
        foreach ($op['lignes'] ?? [] as $ligne) {
            if (\is_array($ligne)) {
                [, $ecart] = $this->ajout->replayOfflineLine($vente, $ligne);
                if ($ecart !== null) {
                    $ecarts[] = $ecart;
                }
            }
        }
        foreach ($op['paiements'] ?? [] as $paiement) {
            if (\is_array($paiement)) {
                // Q-A2 : la synchronisation reste SANS clé de règlement jusqu'à son lot dédié. Une clé
                // venue du poste n'est ni contrôlée ni enregistrée, comme avant le lot 1 du ticket
                // opposable ; seule la clé de l'OPÉRATION (plus haut) protège du rejeu.
                unset($paiement['cleIdempotence']);
                $this->paiement->encaisser($vente, $paiement);
            }
        }

        $this->valider->valider($vente);

        // Tracé APRÈS la validation : une vente qui échoue part en quarantaine, et `clear()` emporte
        // alors l'entrée avec elle — on ne trace pas l'écart d'une vente qui n'existe pas.
        foreach ($ecarts as $ecart) {
            $action = $ecart === $ecartHeure ? 'vente.hors_ligne.horodatage_ecarte' : 'vente.hors_ligne.produit_non_vendable';
            $this->journal->enregistrer($action, 'Vente', (string) $vente->getId(), $vente->getEtablissement()?->getId())
                ->setValeurApres(['raison' => $ecart, 'cleIdempotence' => (string) $cle]);
        }

        // LE LIEN ENTRE LE TICKET PROVISOIRE ET LA VENTE, AU JOURNAL (append-only). Le client garde un
        // ticket au numéro du poste ; sans cette trace, personne ne retrouverait la vente qu'il désigne.
        $this->journal->enregistrer('vente.hors_ligne.remontee', 'Vente', (string) $vente->getId(), $vente->getEtablissement()?->getId())
            ->setValeurApres([
                'numeroLocal' => $this->numeroLocal($op),
                'sequenceLocale' => isset($op['sequenceLocale']) ? (int) $op['sequenceLocale'] : null,
                'horodatageLocal' => \is_string($op['horodatageLocal'] ?? null) ? mb_substr($op['horodatageLocal'], 0, 40) : null,
                'cleIdempotence' => (string) $cle,
                'numero' => $vente->getNumero(),
            ]);

        return [$vente, $ecarts];
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function verifierContinuite(array $operations): void
    {
        $precedente = null;
        foreach ($operations as $op) {
            if (!isset($op['sequenceLocale'])) {
                continue;
            }
            $seq = (int) $op['sequenceLocale'];
            if ($precedente !== null && $seq !== $precedente + 1) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Trou de séquence dans le lot hors-ligne (attendu %d, trouvé %d) : rejet, alerte de contrôle.',
                    $precedente + 1,
                    $seq,
                ));
            }
            $precedente = $seq;
        }
    }

    private function resoudreSession(mixed $reference): SessionCaisse
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException('Référence de session obligatoire.');
        }
        $session = $this->em->getRepository(SessionCaisse::class)->find($uuid);
        if ($session === null) {
            throw new UnprocessableEntityHttpException('Session introuvable.');
        }

        // D8 — la session vient d'un identifiant fourni par le client et etait resolue par un `find()`
        // direct, sans aucun controle. Ce n'est pas qu'une fuite : plus bas, **l'etablissement de la
        // session determine celui de l'objet cree**. Passer la session d'un autre etablissement n'y
        // donnait donc pas seulement acces — cela y creait une ecriture.
        //
        // Troisieme et derniere porte de la meme famille (n10) : les deux autres,
        // `MouvementCaisseProcessor` et `EmettreVenteNoShowProcessor`, ont ete fermees le 19 et le 23/08.
        //
        // Echec ferme en 404 : un 403 confirmerait l'existence de la session ailleurs. Une session sans
        // etablissement echoue aussi — fermeture par defaut.
        $actif = $this->contexte->etablissementActif();
        if ((string) $session->getEtablissement()?->getId() !== (string) $actif?->getId()) {
            throw new NotFoundHttpException('Session introuvable.');
        }

        return $session;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
