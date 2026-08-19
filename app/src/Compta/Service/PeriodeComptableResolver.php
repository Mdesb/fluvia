<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\StatutPeriode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résolution de la période comptable couvrant une date (§0.3 du plan) : factorisation **volontairement
 * dupliquée** depuis `GenerateurEcrituresHandler::periodePour()` (privée, moteur ventes déjà scellé
 * NF525 en production, non refactoré in-place pour risque de régression nul, §0.3). Contrat stable
 * (`pour(ProfilExploitant, DateTimeImmutable): PeriodeComptable`), utilisable sans casser l'existant
 * par un futur refactor du moteur ventes, hors périmètre FIN-1.
 *
 * Deux méthodes, usages distincts :
 * - `resoudre()` (lecture seule) : utilisée par la saisie manuelle (§0.3) — un Comptable doit avoir
 *   ouvert son exercice/mois au préalable, jamais de création silencieuse en saisie humaine.
 * - `resoudreOuCreer()` (crée si absente) : même comportement que `periodePour()`, réservée à un usage
 *   interne différé (futur refactor du moteur ventes), **non appelée par ce lot**.
 */
final class PeriodeComptableResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resoudre(ProfilExploitant $profil, \DateTimeImmutable $date): ?PeriodeComptable
    {
        return $this->em->getRepository(PeriodeComptable::class)->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.profilExploitant) = :profil')
            ->andWhere('p.dateDebut <= :date')
            ->andWhere('p.dateFin >= :date')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->setParameter('date', $date)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function resoudreOuCreer(ProfilExploitant $profil, \DateTimeImmutable $date): PeriodeComptable
    {
        $periode = $this->resoudre($profil, $date);
        if ($periode instanceof PeriodeComptable) {
            return $periode;
        }

        $nouvelle = new PeriodeComptable();
        $nouvelle->setProfilExploitant($profil);
        $nouvelle->setDateDebut(new \DateTimeImmutable($date->format('Y-m-01')));
        $nouvelle->setDateFin(new \DateTimeImmutable($date->format('Y-m-t')));
        $nouvelle->setStatut(StatutPeriode::Ouverte);
        $this->em->persist($nouvelle);

        return $nouvelle;
    }
}
