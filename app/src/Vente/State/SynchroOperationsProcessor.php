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
 *         "lignes": [{"produit":…, "typeTarif":…, "quantite":…}],
 *         "paiements": [{"moyen":…, "montant":…}] } ] }
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class SynchroOperationsProcessor implements ProcessorInterface
{
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

        $operations = \is_array($corps['operations'] ?? null) ? $corps['operations'] : [];

        // Rejeu chronologique par séquence locale.
        usort($operations, static fn (array $a, array $b): int => ((int) ($a['sequenceLocale'] ?? 0)) <=> ((int) ($b['sequenceLocale'] ?? 0)));

        // Trou de séquence dans le lot → rejet (alerte de contrôle), pas d'insertion partielle.
        $this->verifierContinuite($operations);

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

        foreach ($operations as $op) {
            $cle = $this->uuid($op['cleIdempotence'] ?? null);
            if ($cle === null) {
                $quarantaine[] = ['raison' => 'clé d\'idempotence manquante'];
                continue;
            }
            if ($this->em->getRepository(Vente::class)->findOneBy(['cleIdempotence' => $cle]) !== null) {
                $doublons[] = (string) $cle; // rejouer = no-op.
                continue;
            }

            try {
                [$vente, $ecarts] = $this->composer($session, $cle, $op);
                $this->em->flush();
                $inseres[] = (string) $vente->getId();
                foreach ($ecarts as $ecart) {
                    $anomalies[] = ['cle' => (string) $cle, 'vente' => (string) $vente->getId(), 'raison' => $ecart];
                }
            } catch (\Throwable $e) {
                $this->em->clear();
                $session = $this->resoudreSession((string) $session->getId());
                $quarantaine[] = ['cle' => (string) $cle, 'raison' => $e->getMessage()];
            }
        }

        return new JsonResponse([
            'session' => $session->getNumero(),
            'recus' => \count($operations),
            'inseres' => $inseres,
            'doublons' => $doublons,
            'quarantaine' => $quarantaine,
            'anomalies' => $anomalies,
        ], JsonResponse::HTTP_OK);
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
        $this->em->persist($vente);

        // La vente a déjà eu lieu : un produit devenu invendable ne la fait pas refuser (voir
        // `AjoutLigneHandler::replayOfflineLine`). L'écart est tracé plus bas, dans le même flush.
        $ecarts = [];
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
            $this->journal->enregistrer('vente.hors_ligne.produit_non_vendable', 'Vente', (string) $vente->getId(), $vente->getEtablissement()?->getId())
                ->setValeurApres(['raison' => $ecart, 'cleIdempotence' => (string) $cle]);
        }

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
