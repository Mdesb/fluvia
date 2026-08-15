<?php

declare(strict_types=1);

namespace App\Sport\Compta\Port;

use Symfony\Component\Uid\Uuid;

/**
 * Port de projection comptable des encaissements/impayés SEPA (§2.4 du plan, Risque n°1). La chaîne
 * M6 (`GenererEcrituresProcessor`) est couplée à `App\Vente\Entity\Vente` (nécessite une
 * `SessionCaisse`), inadaptée à un encaissement récurrent headless. **Aucun fichier `App\Compta\*`
 * n'est modifié** : l'adaptateur par défaut journalise en file d'attente append-only
 * (`MouvementComptableSepa`), sans écriture NF525 scellée.
 */
interface ProjectionEcritureSepaInterface
{
    public function enregistrerEncaissement(Uuid $etablissementId, Uuid $abonnementId, int $montantCentimes, \DateTimeImmutable $date, string $origine): void;

    public function enregistrerImpaye(Uuid $etablissementId, Uuid $abonnementId, int $montantCentimes, \DateTimeImmutable $date): void;
}
