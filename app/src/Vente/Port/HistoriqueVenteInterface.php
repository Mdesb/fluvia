<?php

declare(strict_types=1);

namespace App\Vente\Port;

/**
 * Frontière M4 (CRM, hors périmètre L2) : historique d'achats par client, pour la fiche 360° (§2.4
 * plan-crm.md). Appelé avec la chaîne complète des identifiants fusionnés pour ne perdre aucun
 * historique après fusion (§4).
 */
interface HistoriqueVenteInterface
{
    /**
     * @param list<\Symfony\Component\Uid\Uuid> $clientIds
     *
     * @return iterable<ResumeVente>
     */
    public function pourClients(array $clientIds): iterable;
}
