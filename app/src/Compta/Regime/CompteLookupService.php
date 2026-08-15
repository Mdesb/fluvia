<?php

declare(strict_types=1);

namespace App\Compta\Regime;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Résolution des objets du plan de comptes (compte/journal/taux) par profil exploitant — service
 * technique partagé par les implémentations de `RegimeComptableInterface` pour éviter de dupliquer
 * l'accès Doctrine dans chaque régime (aucune lecture du discriminant `ProfilExploitant::type` ici).
 */
final class CompteLookupService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function journal(ProfilExploitant $profil, string $code): Journal
    {
        $journal = $this->em->getRepository(Journal::class)->findOneBy([
            'profilExploitant' => $profil->getId(),
            'code' => $code,
        ]);
        if ($journal === null) {
            throw new UnprocessableEntityHttpException(sprintf('Journal « %s » introuvable pour ce profil exploitant.', $code));
        }

        return $journal;
    }

    public function compteParNumero(ProfilExploitant $profil, string $numero): CompteComptable
    {
        $compte = $this->em->getRepository(CompteComptable::class)->findOneBy([
            'profilExploitant' => $profil->getId(),
            'numero' => $numero,
        ]);
        if ($compte === null) {
            throw new UnprocessableEntityHttpException(sprintf('Compte « %s » introuvable pour ce profil exploitant.', $numero));
        }

        return $compte;
    }

    public function compteParPrefixe(ProfilExploitant $profil, string $prefixe): CompteComptable
    {
        $comptes = $this->em->getRepository(CompteComptable::class)->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.profilExploitant) = :profil')
            ->andWhere('c.numero LIKE :prefixe')
            ->andWhere('c.actif = true')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->setParameter('prefixe', $prefixe . '%')
            ->orderBy('c.numero', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if ($comptes === []) {
            throw new UnprocessableEntityHttpException(sprintf('Aucun compte préfixé « %s » pour ce profil exploitant.', $prefixe));
        }

        return $comptes[0];
    }

    /** Taux « hors champ » (0 %) utilisé pour les opérations non commerciales (versement, extourne miroir). */
    public function tauxHorsChamp(ProfilExploitant $profil): TauxTva
    {
        $taux = $this->em->getRepository(TauxTva::class)->findOneBy([
            'profilExploitant' => $profil->getId(),
            'libelle' => TauxTva::LIBELLE_HORS_CHAMP,
        ]);
        if ($taux === null) {
            throw new UnprocessableEntityHttpException('Taux « hors champ » introuvable pour ce profil exploitant (seed manquant).');
        }

        return $taux;
    }
}
