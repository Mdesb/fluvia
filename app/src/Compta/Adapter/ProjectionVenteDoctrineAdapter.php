<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Port\ProjectionVenteInterface;
use App\Compta\Regime\Dto\AvoirProjectionDto;
use App\Compta\Regime\Dto\LigneVenteProjectionDto;
use App\Compta\Regime\Dto\VenteProjectionDto;
use App\Offre\Entity\Produit;
use App\Offre\Enum\AxeCategorie;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seul point de contact avec les entités M2/M1 pour la génération d'écritures (§2 du plan, §4). Lit
 * `App\Vente\Entity\Vente` en lecture seule, convertit les `decimal` M2 en **centimes** (frontière de
 * conversion, `bcmul`/arrondi bancaire), ventile la TVA ligne à ligne via la catégorie comptable (M1).
 * Ne modifie jamais M2/M1.
 */
final class ProjectionVenteDoctrineAdapter implements ProjectionVenteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ventesValideesNonComptabilisees(ProfilExploitant $profil): iterable
    {
        $etablissementIds = $this->etablissementIdsAsStrings($profil);
        if ($etablissementIds === []) {
            return;
        }

        $dejaComptabilisees = $this->venteOriginesDejaComptabilisees($profil);

        // Filtrage de l'établissement en PHP (pas en DQL) : `IN(:tableau)` avec un tableau
        // d'entités/UUID s'est révélé peu fiable selon le contexte d'exécution de la requête
        // (l'expansion de la liste de paramètres n'est pas systématique) — filtrage explicite ici pour
        // rester robuste, le volume par profil restant modeste (établissements rattachés, non tout le
        // catalogue de ventes de l'application).
        /** @var list<Vente> $ventes */
        $ventes = $this->em->getRepository(Vente::class)->createQueryBuilder('v')
            ->andWhere('v.statut = :statut')
            ->setParameter('statut', StatutVente::Validee)
            ->orderBy('v.date', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($ventes as $vente) {
            $etabId = $vente->getEtablissement()?->getId();
            if ($etabId === null || !\in_array((string) $etabId, $etablissementIds, true)) {
                continue;
            }
            if (\in_array((string) $vente->getId(), $dejaComptabilisees, true)) {
                continue;
            }

            yield $this->projeterVente($vente);
        }
    }

    public function avoirsNonComptabilises(ProfilExploitant $profil): iterable
    {
        $etablissementIds = $this->etablissementIdsAsStrings($profil);
        if ($etablissementIds === []) {
            return;
        }

        $dejaExtournes = $this->venteOriginesDejaExtournees($profil);

        /** @var list<Avoir> $avoirs */
        $avoirs = $this->em->getRepository(Avoir::class)->createQueryBuilder('a')
            ->orderBy('a.dateHeure', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($avoirs as $avoir) {
            $etabId = $avoir->getEtablissement()?->getId();
            if ($etabId === null || !\in_array((string) $etabId, $etablissementIds, true)) {
                continue;
            }
            $venteOrigine = $avoir->getVenteOrigine();
            if ($venteOrigine === null) {
                continue;
            }
            if (\in_array((string) $venteOrigine->getId(), $dejaExtournes, true)) {
                continue;
            }
            // L'écriture d'origine doit exister (sinon rien à extourner, sera repris au prochain passage).
            if (!\in_array((string) $venteOrigine->getId(), $this->venteOriginesDejaComptabilisees($profil), true)) {
                continue;
            }

            yield new AvoirProjectionDto(
                id: $avoir->getId(),
                venteOrigine: $venteOrigine->getId(),
                montantCentimes: $this->centimes($avoir->getMontant()),
                motif: $avoir->getMotif(),
                dateHeure: $avoir->getDateHeure(),
            );
        }
    }

    private function projeterVente(Vente $vente): VenteProjectionDto
    {
        $lignes = [];
        foreach ($vente->getLignes() as $ligneVente) {
            $produit = $this->em->getRepository(Produit::class)->find($ligneVente->getProduit());
            $categorieComptable = $produit?->getCategorieParAxe(AxeCategorie::Comptable)?->getId();

            $identifiantSupport = null;
            /** @var BilletSupport|null $support */
            $support = $this->em->getRepository(BilletSupport::class)->findOneBy(['ligne' => $ligneVente->getId()]);
            if ($support !== null) {
                $identifiantSupport = $support->getIdentifiantSupport();
            }

            $lignes[] = new LigneVenteProjectionDto(
                produit: $ligneVente->getProduit(),
                categorieComptable: $categorieComptable,
                montantTtcCentimes: $this->centimes($ligneVente->getMontantLigne()),
                reglePca: $produit?->getReglePca() ?? \App\Offre\Enum\ReglePca::Aucune,
                dureeValidite: $produit?->getDureeValidite(),
                nbCrediteCarte: $produit?->getCarte()?->getNbCredite(),
                identifiantSupport: $identifiantSupport,
            );
        }

        return new VenteProjectionDto(
            id: $vente->getId(),
            numero: $vente->getNumero(),
            etablissement: $vente->getEtablissement()?->getId() ?? $vente->getId(),
            date: $vente->getDate(),
            lignes: $lignes,
            totalTtcCentimes: $this->centimes($vente->getTotal()),
        );
    }

    /**
     * Établissements du périmètre du profil, en UUID (chaîne) — comparés en PHP (§ note ci-dessus,
     * `IN(:tableau)` DQL non fiable dans ce contexte pour un tableau d'entités/UUID).
     *
     * @return list<string>
     */
    private function etablissementIdsAsStrings(ProfilExploitant $profil): array
    {
        $ids = [];
        if ($profil->getEtablissementPrincipal() !== null) {
            $ids[(string) $profil->getEtablissementPrincipal()->getId()] = true;
        }
        foreach ($profil->getEtablissementsRattaches() as $etab) {
            $ids[(string) $etab->getId()] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return list<string> UUID (string) de Vente déjà rattachées à une écriture du journal ventes.
     *
     * Récupère les **entités** (pas `getScalarResult()`) : la colonne `venteOrigine` est un UUID
     * scalaire (pas une association) et l'hydratation SCALAR de Doctrine ne restitue ici que la
     * valeur binaire brute (aucune conversion via le type Doctrine `uuid`), contrairement à
     * l'hydratation d'entité qui applique correctement `UuidType::convertToPHPValue()`.
     */
    private function venteOriginesDejaComptabilisees(ProfilExploitant $profil): array
    {
        /** @var list<EcritureComptable> $ecritures */
        $ecritures = $this->em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.profilExploitant) = :profil')
            ->andWhere('e.venteOrigine IS NOT NULL')
            ->andWhere('e.pieceExtourneDe IS NULL')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        return array_values(array_unique(array_map(
            static fn (EcritureComptable $e): string => (string) $e->getVenteOrigine(),
            $ecritures,
        )));
    }

    /** @return list<string> UUID (string) de Vente déjà extournées (écriture d'extourne générée). */
    private function venteOriginesDejaExtournees(ProfilExploitant $profil): array
    {
        /** @var list<EcritureComptable> $ecritures */
        $ecritures = $this->em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.profilExploitant) = :profil')
            ->andWhere('e.venteOrigine IS NOT NULL')
            ->andWhere('e.pieceExtourneDe IS NOT NULL')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        return array_values(array_unique(array_map(
            static fn (EcritureComptable $e): string => (string) $e->getVenteOrigine(),
            $ecritures,
        )));
    }

    /** Frontière de conversion decimal (M2) → centimes (arrondi bancaire, cf. §1 du plan). */
    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
