<?php

declare(strict_types=1);

namespace App\Membership\Sepa;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\RemiseSepa as SepaRemiseSepa;
use App\Sepa\Port\EcheanceSepaSource;
use App\Offre\Entity\Produit;
use App\Membership\Entity\Membership;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Enum\StatutEcheanceSepa;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implémentation Sport du port `EcheanceSepaSource` (plan §3/§5) : fournit au module SEPA partagé les
 * échéances dues de l'échéancier fitness (`App\Membership\Entity\EcheanceSepa`, resté propre à Sport), en
 * indiquant la dernière échéance de chaque engagement à durée déterminée (pour `FNAL`). Taguée
 * `sepa.echeance_source` (services.yaml) pour être agrégée par `CompositeEcheanceSepaSource`.
 */
final class SportEcheanceSepaSource implements EcheanceSepaSource
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array
    {
        /** @var list<EcheanceSepa> $echeances */
        $echeances = $this->em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->addSelect('a')
            ->join('a.mandatSepa', 'm')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecution, 'date_immutable')
            ->getQuery()->getResult();

        if ($echeances === []) {
            return [];
        }

        // Dernière échéance « à venir » de chaque abonnement (engagement à durée déterminée, pour FNAL).
        $derniereDateParAbonnement = [];
        foreach ($echeances as $echeance) {
            $idAbonnement = (string) $echeance->getAbonnement()->getId();
            $date = $echeance->getDateProgrammee();
            if (!isset($derniereDateParAbonnement[$idAbonnement]) || $date > $derniereDateParAbonnement[$idAbonnement]) {
                $derniereDateParAbonnement[$idAbonnement] = $date;
            }
        }

        $tauxParFormule = $this->tauxParFormule($echeances);

        $dues = [];
        foreach ($echeances as $echeance) {
            $abonnement = $echeance->getAbonnement();
            \assert($abonnement instanceof Membership);
            $mandat = $abonnement->getMandatSepa();
            if ($mandat === null) {
                continue;
            }
            $idAbonnement = (string) $abonnement->getId();
            $derniere = $derniereDateParAbonnement[$idAbonnement] == $echeance->getDateProgrammee();
            $formuleAbonnement = $abonnement->getFormule();
            $idFormule = $formuleAbonnement !== null ? bin2hex($formuleAbonnement->getId()->toBinary()) : '';

            $dues[] = new EcheanceSepaDue(
                referenceOrigine: (string) $echeance->getId(),
                mandatId: $mandat->getId(),
                montantCentimes: $echeance->getMontantCentimes(),
                libelle: 'Abonnement fitness ' . $mandat->getRum(),
                dateEcheance: $echeance->getDateProgrammee(),
                derniereEcheanceEngagement: $derniere,
                paiementUnique: false,
                tauxTvaValeur: $tauxParFormule[$idFormule] ?? null,
            );
        }

        return $dues;
    }

    /**
     * Le taux de TVA de chaque formule, par identifiant de formule.
     *
     * ⚠ UNE REQUÊTE POUR TOUTES LES ÉCHÉANCES, PAS UNE PAR ÉCHÉANCE. Une remise mensuelle porte une
     * ligne par abonné : résoudre le taux dans la boucle produirait un N+1 qui ne se verrait qu'en
     * production, quand le nombre d'abonnés aura grandi.
     *
     * ⚠ ET LA RELATION SE PARCOURT À L'ENVERS. `Produit.formule` est le côté PROPRIÉTAIRE d'un
     * `OneToOne` sans côté inverse : depuis une `Formule`, il n'existe aucun accesseur vers son
     * `Produit`. On interroge donc les produits PAR leurs formules, ce qui est le seul chemin
     * disponible — et non un choix de style.
     *
     * @param list<EcheanceSepa> $echeances
     *
     * @return array<string, string> identifiant de formule → taux décimal (« 20.00 »)
     */
    private function tauxParFormule(array $echeances): array
    {
        $formules = [];
        foreach ($echeances as $echeance) {
            $formule = $echeance->getAbonnement()?->getFormule();
            if ($formule !== null) {
                // La clé est l'hexadécimal du binaire : c'est la forme que rend `HEX(formule_id)`
                // ci-dessous, donc la seule qui se compare sans conversion supplémentaire.
                $formules[bin2hex($formule->getId()->toBinary())] = true;
            }
        }

        if ($formules === []) {
            return [];
        }

        // ⚠ SQL DIRECT AVEC `UNHEX`, ET CE N'EST PAS UN CAPRICE — le garde-fou n°? l'a attrapé sur la
        //    première version de cette méthode, qui écrivait `->where('p.formule IN (:formules)')`.
        //    `setParameter` NE CONVERTIT PAS les éléments d'un tableau : la requête aurait rendu une
        //    liste VIDE, sans lever. Le taux n'aurait donc jamais été résolu, et le symptôme aurait
        //    été « aucun produit ne porte de taux » — un défaut qui se déguise en donnée manquante.
        $identifiants = array_keys($formules);
        $placeholders = implode(',', array_fill(0, \count($identifiants), 'UNHEX(?)'));

        /** @var list<array{formule: string, taux: string|null}> $lignes */
        $lignes = $this->em->getConnection()->fetchAllAssociative(
            'SELECT LOWER(HEX(formule_id)) AS formule, taux_tva AS taux
               FROM off_produit
              WHERE formule_id IN (' . $placeholders . ')',
            $identifiants,
        );

        $taux = [];
        foreach ($lignes as $ligne) {
            if ($ligne['taux'] !== null && $ligne['taux'] !== '') {
                $taux[(string) $ligne['formule']] = (string) $ligne['taux'];
            }
        }

        return $taux;
    }

    public function marquerCollectees(SepaRemiseSepa $remise, array $referencesOrigine): void
    {
        $repository = $this->em->getRepository(EcheanceSepa::class);
        foreach ($referencesOrigine as $reference) {
            $echeance = $repository->find($reference);
            if (!$echeance instanceof EcheanceSepa) {
                // Référence appartenant à une autre verticale (agrégation `CompositeEcheanceSepaSource`).
                continue;
            }
            $echeance->setRemise($remise);
            $echeance->setStatut(StatutEcheanceSepa::Prelevee);
            $echeance->setDateExecutionReelle(new \DateTimeImmutable());
        }
    }
}
