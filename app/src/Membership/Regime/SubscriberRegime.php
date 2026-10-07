<?php

declare(strict_types=1);

namespace App\Membership\Regime;

use App\Crm\Enum\TypeClient;

/**
 * Le régime juridique d'un abonnement, selon le PROFIL du payeur (D113, audit du 14/09, #96).
 *
 * - **Consommateur** (payeur personne physique, souscription notamment en ligne) : droit de la
 *   consommation — rétractation (L221-28), information de non-reconduction tacite (L215-1), préavis
 *   de résiliation plafonné.
 * - **Professionnel / collectivité** (personne morale) : relève de son marché ; pas de protection
 *   consommateur.
 *
 * ⚠ CE QUE CE LOT FIXE (l'audit reprochait un régime « non fixé ») : le profil et ses règles, comme
 * type consultable par le reste du code. Ce qu'il NE fait PAS encore (lot 2) : le flux de rétractation
 * lui-même et la notification de non-reconduction. Voir spec-regime-par-profil.md.
 *
 * ⚠ VALEURS SOUMISES À VALIDATION (Maxime, juriste). Le plafond de préavis consommateur à 30 jours est
 * un choix protecteur usuel, pas une valeur imposée par un texte unique — à arrêter.
 */
enum SubscriberRegime: string
{
    case Consumer = 'consumer';
    case Professional = 'professional';

    /**
     * Profil déduit du type de payeur. Défaut protecteur : hors personne morale identifiée, on retient
     * le régime consommateur (le plus protecteur) plutôt que de supposer un professionnel.
     */
    public static function pour(?TypeClient $typePayeur): self
    {
        return $typePayeur === TypeClient::Morale ? self::Professional : self::Consumer;
    }

    /** Droit de rétractation (L221-28) applicable à une souscription à distance ? */
    public function retractationApplicable(): bool
    {
        return $this === self::Consumer;
    }

    /** Information de la faculté de non-reconduction tacite due au client (L215-1) ? */
    public function reconductionTaciteInfoRequise(): bool
    {
        return $this === self::Consumer;
    }

    /** Préavis de résiliation maximal opposable, en jours — `null` = pas de plafond (le marché fixe). */
    public function preavisResiliationMaxJours(): ?int
    {
        return $this === self::Consumer ? 30 : null;
    }

    /** Le préavis demandé dépasse-t-il le plafond opposable à ce profil ? (borne stricte : égal = OK.) */
    public function preavisResiliationDepasse(int $preavisJours): bool
    {
        $max = $this->preavisResiliationMaxJours();

        return $max !== null && $preavisJours > $max;
    }
}
