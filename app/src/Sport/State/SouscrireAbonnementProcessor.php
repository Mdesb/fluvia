<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Service\SouscriptionAbonnementHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sport/abonnements/souscrire (US-SPORT-01, CA-1). Corps :
 *   { "adherent": iri|uuid, "payeur": iri|uuid, "formule": iri|uuid,
 *     "dateSouscription"?: "AAAA-MM-JJ", "dureeEngagementMois": int,
 *     "montantPremiereEcheanceCentimes"?: int,  // prorata d’entree ; 0 accepte (mois offert)
 *     "iban": string, "titulaireMandat": string }
 * L'IBAN en clair transite uniquement ici (jamais mappé Doctrine, tokenisé avant persistance, §4 du plan).
 *
 * @implements ProcessorInterface<mixed, AbonnementFitness>
 */
final class SouscrireAbonnementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly SouscriptionAbonnementHandler $handler,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AbonnementFitness
    {
        $corps = $this->lecteur->corps();

        $adherent = $this->resoudre(Beneficiaire::class, $corps['adherent'] ?? null);
        $payeur = $this->resoudre(Client::class, $corps['payeur'] ?? null);
        $formule = $this->resoudre(Formule::class, $corps['formule'] ?? null);
        if (!$adherent instanceof Beneficiaire || !$payeur instanceof Client || !$formule instanceof Formule) {
            throw new UnprocessableEntityHttpException('« adherent », « payeur » et « formule » sont requis et doivent référencer des ressources existantes.');
        }

        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        // ⚠ `periodicite` N'EST PLUS LU, ET SON ENVOI EST REFUSÉ PLUTÔT QU'IGNORÉ.
        //
        // Arbitrage de Maxime du 06/09 : le contrat gèle les termes de l'offre. La cadence vient
        // désormais de `Formule::$periodicite` — un champ déjà éditable dans la fiche produit, et
        // qui n'était lu par personne : une formule ANNUELLE souscrite ici devenait MENSUELLE.
        //
        // L'accepter en silence serait pire que de le refuser : un appelant continuerait de
        // l'envoyer, croirait fixer la cadence, et celle de la formule s'appliquerait à sa place —
        // sans erreur, sans message, et sur un prélèvement. Même raison que `montantCentimes`.
        if (isset($corps['periodicite'])) {
            throw new UnprocessableEntityHttpException(
                '« periodicite » n\'est plus accepté : la cadence vient de la formule du produit. '
                . 'Renseignez-la dans la fiche produit.',
            );
        }

        $iban = \is_string($corps['iban'] ?? null) ? $corps['iban'] : '';
        $titulaire = \is_string($corps['titulaireMandat'] ?? null) ? $corps['titulaireMandat'] : '';
        if (trim($iban) === '' || trim($titulaire) === '') {
            throw new UnprocessableEntityHttpException('« iban » et « titulaireMandat » sont requis pour signer le mandat SEPA.');
        }

        $dateSouscription = isset($corps['dateSouscription']) && \is_string($corps['dateSouscription'])
            ? new \DateTimeImmutable($corps['dateSouscription'])
            : new \DateTimeImmutable('today');
        $dureeEngagementMois = isset($corps['dureeEngagementMois']) ? (int) $corps['dureeEngagementMois'] : 12;
        // ⚠ `montantCentimes` N'EST PLUS LU, ET SON ENVOI EST REFUSÉ PLUTÔT QU'IGNORÉ.
        //
        // Arbitrage de Maxime du 01/09 : « il ne doit pas y avoir de prix libre. » Le prix vient
        // désormais de la grille tarifaire du produit, comme sur la boutique.
        //
        // L'accepter en silence serait pire que de le refuser : un appelant continuerait de
        // l'envoyer, croirait fixer le prix, et le tarif s'appliquerait à sa place — sans erreur,
        // sans message, et sur un prélèvement.
        if (isset($corps['montantCentimes'])) {
            throw new UnprocessableEntityHttpException(
                '« montantCentimes » n\'est plus accepté : le prix est résolu depuis la grille '
                . 'tarifaire du produit qui porte cette formule. Retirez ce champ.',
            );
        }

        // ── LE PRORATA D'ENTRÉE, FACULTATIF ───────────────────────────────────────────────────
        //
        // Une souscription en cours de période fait payer un demi-mois d'abord, puis le plein tarif.
        // C'est un MONTANT fourni, jamais un calcul fait ici : au prorata de quoi, arrondi comment,
        // à partir de quelle date sont des décisions commerciales, et une règle inventée
        // s'appliquerait en silence à toutes les souscriptions.
        //
        // ⚠ ZÉRO EST ACCEPTÉ, ET C'EST DÉLIBÉRÉ : le premier mois offert est une pratique courante.
        //    Seul un montant NÉGATIF est refusé — il n'y a pas de prélèvement négatif, et le laisser
        //    passer produirait une remise que la banque rejetterait, longtemps après la vente.
        $montantPremiereCentimes = null;
        if (isset($corps['montantPremiereEcheanceCentimes'])) {
            $montantPremiereCentimes = (int) $corps['montantPremiereEcheanceCentimes'];
            if ($montantPremiereCentimes < 0) {
                throw new UnprocessableEntityHttpException('« montantPremiereEcheanceCentimes » ne peut pas être négatif.');
            }
        }

        return $this->handler->souscrire(
            $adherent,
            $payeur,
            $formule,
            $etablissement,
            $dateSouscription,
            $dureeEngagementMois,
            $iban,
            $titulaire,
            $montantPremiereCentimes,
        );
    }

    /** @param class-string $classe */
    private function resoudre(string $classe, mixed $reference): ?object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            return null;
        }

        return $this->em->getRepository($classe)->find($uuid);
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
