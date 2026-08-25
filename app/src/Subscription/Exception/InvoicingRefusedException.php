<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * Facturation refusée pour une raison métier, pas technique (ED-7).
 *
 * Exception dédiée parce que l'appelant doit pouvoir distinguer « ce mois ne se facture pas » d'une
 * panne : le premier appelle une correction de configuration ou de statut, le second une reprise.
 * Le message s'adresse à l'exploitant et dit quoi faire — « configurez un taux de TVA », « précisez
 * celui qui s'applique » — parce qu'une facturation bloquée sans cause lisible finit en facture
 * saisie à la main, hors de toute chaîne de scellement.
 */
final class InvoicingRefusedException extends \RuntimeException
{
}
