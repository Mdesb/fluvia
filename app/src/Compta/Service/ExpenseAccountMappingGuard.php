<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\ExpenseAccountMapping;
use App\Compta\Entity\ProfilExploitant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Garde le mapping des charges (RG-M6-12, CA-3), symétrique de `MappingComptableGuard` côté produits :
 * une nature de charge sans `ExpenseAccountMapping` actif **bloque** la génération d'écriture pour la
 * dépense concernée — le document appelant (supplier invoice, expense report, FIN-2/FIN-3) reste en
 * brouillon/à traiter, aucune exception n'est levée ici (dégradation propre, même patron que
 * `MappingComptableGuard`).
 *
 * Public explicitement (`#[Autoconfigure(public: true)]`, même patron que `App\Adhesion\Service\
 * CycleVieAdhesionHandler`) : ce lot ne le consomme pas encore lui-même (prêt pour FIN-2/FIN-3,
 * §4.5 spec) — sans cette annotation, le compilateur DI le retire du conteneur (service non
 * référencé), le rendant inaccessible même en test.
 */
#[Autoconfigure(public: true)]
final class ExpenseAccountMappingGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<string> anomalies (vide = mapping complet) */
    public function anomalies(ProfilExploitant $profil, string $expenseNatureCode): array
    {
        $mapping = $this->trouver($profil, $expenseNatureCode);
        if ($mapping === null || !$mapping->estValide()) {
            return [sprintf('Nature de charge %s : mapping comptable incomplet ou inactif (CA-3).', $expenseNatureCode)];
        }

        return [];
    }

    /** Résout le mapping actif pour la nature de charge, ou `null` si incomplet/inactif/absent. */
    public function resoudre(ProfilExploitant $profil, string $expenseNatureCode): ?ExpenseAccountMapping
    {
        $mapping = $this->trouver($profil, $expenseNatureCode);

        return ($mapping !== null && $mapping->estValide()) ? $mapping : null;
    }

    private function trouver(ProfilExploitant $profil, string $expenseNatureCode): ?ExpenseAccountMapping
    {
        return $this->em->getRepository(ExpenseAccountMapping::class)->findOneBy([
            'businessProfile' => $profil->getId(),
            'expenseNatureCode' => $expenseNatureCode,
        ]);
    }
}
