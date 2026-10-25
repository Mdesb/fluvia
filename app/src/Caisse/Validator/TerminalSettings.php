<?php

declare(strict_types=1);

namespace App\Caisse\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Le réglage du terminal de paiement d'un point de vente (`PointDeVente.tpe`, spec TPE lot 1 §6) :
 * nul (le terminal externe), ou `{ connecteur, …paramètres }` avec un connecteur DISPONIBLE.
 *
 * `simule` n'est permis qu'en test et en démonstration (`TPE_SIMULE_AUTORISE`) : en production, il
 * encaisserait une carte que personne n'a présentée. Les connecteurs des lots suivants sont refusés
 * tant qu'ils n'existent pas : déclarés, ils feraient échouer chaque carte au guichet.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class TerminalSettings extends Constraint
{
}
