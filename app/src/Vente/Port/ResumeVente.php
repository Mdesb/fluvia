<?php

declare(strict_types=1);

namespace App\Vente\Port;

use Symfony\Component\Uid\Uuid;

/**
 * Résumé d'une vente exposé à M4 pour l'historique agrégé (fiche 360°, §2.4 plan-crm.md).
 * `roles` indique, pour le(s) client(s) interrogé(s), si la vente le concerne en tant que payeur
 * (`Vente.client`) et/ou bénéficiaire d'au moins une ligne (`LigneVente.beneficiaire`), CA-5.
 */
final class ResumeVente
{
    /**
     * @param array<string, list<string>> $roles clientId (string) => liste parmi ['payeur', 'beneficiaire']
     */
    public function __construct(
        public readonly Uuid $venteId,
        public readonly string $numero,
        public readonly \DateTimeImmutable $date,
        public readonly string $total,
        public readonly array $roles,
    ) {
    }
}
