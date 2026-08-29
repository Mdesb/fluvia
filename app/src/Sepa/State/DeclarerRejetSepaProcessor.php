<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\RejetSepa;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Enum\StatutIncidentImpaye;
use App\Recouvrement\Service\MoteurRecouvrementHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sepa/rejets (plan §2/§4/§6) : saisie/simulation manuelle d'un retour SEPA en attendant le
 * retour bancaire réel (`RetourSepaInterface`, aucun parser pain.002 réel — §9 du plan). Corps :
 *   { "ligne": iri|uuid, "codeMotif": string, "libelleMotif"?: string, "dateRejet"?: "AAAA-MM-JJ" }.
 *
 * @implements ProcessorInterface<mixed, RejetSepa>
 */
final class DeclarerRejetSepaProcessor implements ProcessorInterface
{
    /**
     * Type de redevable generique : le client porteur du mandat.
     *
     * ⚠ AUCUN PORT NE L'IMPLEMENTE ENCORE. `RedevableRegistry` n'en connait qu'un,
     * `sport.abonnement_fitness` : un incident ouvert sous ce type-ci ne coupera donc aucun acces.
     * La valeur est posee des maintenant pour que les incidents deja ouverts soient rattrapes le
     * jour ou le port existera, plutot que de porter un type invente apres coup.
     */
    public const TYPE_REDEVABLE_CLIENT = 'crm.client';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
        private readonly MoteurRecouvrementHandler $recouvrement,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RejetSepa
    {
        $corps = $this->lecteur->corps();

        $ligne = $this->resoudreLigne($corps['ligne'] ?? null);
        if (!$ligne instanceof LigneRemiseSepa) {
            throw new UnprocessableEntityHttpException('« ligne » est requise et doit référencer une ligne de remise SEPA existante.');
        }

        $this->assertLigneDansLePerimetre($ligne);

        $codeMotif = \is_string($corps['codeMotif'] ?? null) ? trim($corps['codeMotif']) : '';
        if ($codeMotif === '') {
            throw new UnprocessableEntityHttpException('« codeMotif » est requis (code retour SEPA, ex. AM04).');
        }
        $libelle = isset($corps['libelleMotif']) && \is_string($corps['libelleMotif']) ? $corps['libelleMotif'] : null;
        $dateRejet = isset($corps['dateRejet']) && \is_string($corps['dateRejet'])
            ? new \DateTimeImmutable($corps['dateRejet'])
            : new \DateTimeImmutable('today');

        $mandat = $ligne->getMandat();

        $rejet = new RejetSepa();
        $rejet->setLigne($ligne)
            ->setEndToEndId($ligne->getEndToEndId())
            ->setMndtId($mandat?->getRum() ?? '')
            ->setCodeMotif($codeMotif)
            ->setLibelleMotif($libelle)
            ->setDateRejet($dateRejet);

        $this->em->persist($rejet);
        $this->em->flush();

        $this->ouvrirIncident($rejet, $ligne, $dateRejet, $codeMotif, $libelle);

        return $rejet;
    }

    /**
     * OUVRE L'IMPAYE QUE LE REJET DECLENCHE.
     *
     * Jusqu'ici, declarer un rejet n'ecrivait qu'une ligne au journal. Le moteur de recouvrement
     * existait, son parametre `?RejetSepa $rejetSepa` avait ete prevu pour recevoir un retour
     * bancaire reel, et personne ne le lui passait : son seul appelant etait une simulation du
     * module Sport. Deux ecrans affirmaient pourtant qu'un impaye etait ouvert.
     *
     * ── LE REDEVABLE EST LE CLIENT DU MANDAT, ET C'EST PROVISOIRE ───────────────────────────────
     *
     * `PropagationAccesHandler` resout le droit d'acces a couper via un PORT par type de redevable,
     * et il n'en existe qu'un : `sport.abonnement_fitness`. Un rejet sur un mandat qui n'est pas un
     * abonnement fitness ouvre donc un incident qui ne ferme aucune porte.
     *
     * C'est sans danger — `appliquer()` rend `null` pour un type inconnu, ne modifie rien, et emet
     * son evenement — mais c'est aussi sans effet. Tant qu'un port au niveau client n'existe pas,
     * l'ecran ne doit pas promettre que l'acces est coupe.
     *
     * ── ON N'OUVRE PAS DEUX FOIS LE MEME IMPAYE ─────────────────────────────────────────────────
     *
     * Declarer deux fois le meme rejet est un geste d'exploitant courant — on rafraichit, on
     * recommence. Sans garde, chaque declaration ouvrirait son incident, et le tableau de bord
     * compterait deux impayes la ou il y en a un.
     *
     * ── ET SI LE REDEVABLE N'EST PAS RESOLVABLE, ON N'EMPECHE PAS L'ENREGISTREMENT ──────────────
     *
     * Le rejet lui-meme est deja ecrit et flushe. Un mandat sans client, ou une remise sans
     * etablissement, ne doit pas faire echouer la saisie : perdre la trace du rejet serait pire que
     * de ne pas ouvrir l'incident.
     */
    private function ouvrirIncident(
        RejetSepa $rejet,
        LigneRemiseSepa $ligne,
        \DateTimeImmutable $dateRejet,
        string $codeMotif,
        ?string $libelleMotif,
    ): void {
        $etablissement = $ligne->getRemise()?->getEtablissement();
        $client = $ligne->getMandat()?->getClient();
        if ($etablissement === null || $client === null) {
            return;
        }

        $reference = (string) $client->getId();
        $origine = $ligne->getReferenceOrigine();

        if ($origine !== null && $this->incidentDejaOuvert($origine, $reference)) {
            return;
        }

        $this->recouvrement->detecterRejet(
            etablissement: $etablissement,
            typeRedevable: self::TYPE_REDEVABLE_CLIENT,
            referenceRedevable: $reference,
            montantCentimes: $ligne->getMontantCentimes(),
            dateRejet: $dateRejet,
            codeRetour: $codeMotif,
            libelleRetour: $libelleMotif,
            referenceEcheanceOrigine: $origine,
            rejetSepa: $rejet,
        );
    }

    /**
     * Un impaye non solde existe-t-il deja pour cette echeance et ce redevable ?
     *
     * On borne sur le couple, et non sur la seule echeance : deux redevables peuvent partager une
     * reference d'origine si une verticale la fabrique sans garantie d'unicite.
     */
    private function incidentDejaOuvert(string $origine, string $reference): bool
    {
        $existants = $this->em->getRepository(IncidentImpaye::class)->findBy([
            'referenceEcheanceOrigine' => $origine,
            'referenceRedevable' => $reference,
        ]);

        foreach ($existants as $incident) {
            if ($incident->getStatut() !== StatutIncidentImpaye::Resolu) {
                return true;
            }
        }

        return false;
    }


    /**
     * Cloisonnement du retour SEPA (D3, D8).
     *
     * La ligne de remise est résolue depuis un UUID fourni dans le corps de la requête : elle échappe
     * donc par construction aux extensions Doctrine, qui ne s'exécutent que sur les opérations de
     * **lecture** d'API Platform. L'autorité se recalcule ici contre l'établissement de la **remise
     * visée**, jamais contre l'en-tête `X-Etablissement` fourni par le client.
     *
     * Sans ce contrôle, un utilisateur habilité sur un établissement peut déclarer un rejet sur le
     * prélèvement d'un autre — un chemin argent, avec un effet comptable direct.
     */
    private function assertLigneDansLePerimetre(LigneRemiseSepa $ligne): void
    {
        $utilisateur = $this->security->getUser();
        $etablissement = $ligne->getRemise()?->getEtablissement();

        $codes = $utilisateur instanceof Utilisateur && $etablissement !== null
            ? $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId())
            : [];

        // Échec fermé, et 404 : distinguer « hors périmètre » de « inexistant » renseignerait déjà
        // l'appelant sur les remises d'un autre établissement.
        if (!$this->calculateur->autorise($codes, 'sepa', 'declarer_rejet')) {
            throw new NotFoundHttpException('Ligne de remise introuvable.');
        }
    }

    private function resoudreLigne(mixed $valeur): ?LigneRemiseSepa
    {
        if (!\is_string($valeur) || trim($valeur) === '') {
            return null;
        }
        $id = str_contains($valeur, '/') ? substr($valeur, (int) strrpos($valeur, '/') + 1) : $valeur;
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(LigneRemiseSepa::class)->find($id);
    }
}
