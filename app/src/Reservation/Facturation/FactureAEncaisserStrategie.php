<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Securite\Entity\Utilisateur;

/**
 * Squelette purement déclaratif (décision structurante n°4 du plan) : aucun objet « Facture » côté
 * M6 n'existe dans ce dépôt. Ne modifie jamais `FacturationNoShow.statut` (reste `à_facturer`) —
 * documente seulement l'intention, en attendant un lot M6 dédié.
 */
final class FactureAEncaisserStrategie implements StrategieFacturationNoShow
{
    public function code(): string
    {
        return ModeFacturationNoShow::FactureAEncaisser->value;
    }

    public function appliquer(FacturationNoShow $facturation, ?Utilisateur $agent, array $contexte = []): ResultatFacturationNoShow
    {
        return new ResultatFacturationNoShow(
            false,
            'Facture à encaisser : objet Facture (M6) non modélisé dans ce dépôt — squelette déclaratif (Risque n°1 du plan).',
        );
    }
}
