<?php

declare(strict_types=1);

namespace App\Sepa\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * UN DÉLAI DE PRÉAVIS PLUS LONG QUE LA PÉRIODE ÉCARTE CHAQUE ÉCHÉANCE — §8.5.
 *
 * ── LE MÉCANISME, MESURÉ PAR `allaccess-c0` LE 03/09 ────────────────────────────────────────────
 *
 * La reconduction crée la première échéance neuve **une période** après la dernière
 * (`SubscriptionTermHandler` : `+1 month` ou `+1 week`). Si le délai de préavis du créancier dépasse
 * cette période, l'échéance tombe trop tôt pour être couverte : `GenerationRemiseHandler` l'écarte
 * de la remise.
 *
 * ⚠ **ELLE N'EST PAS PERDUE** — elle est comptée, le motif est enregistré, elle reste collectable.
 * Elle GLISSE, elle ne disparaît pas. C'est ce qui rend le défaut si discret : aucun montant ne
 * manque, aucun message d'erreur ; simplement, aucun prélèvement ne part ce cycle-là.
 *
 * ── ⚠ LA BORNE EST STRICTE, ET C'EST MESURÉ ────────────────────────────────────────────────────
 *
 * `fada41fd` / `DebitPreNotifierTest` : `reasonNotCovered` refuse quand
 * `sentAt > executionDate - délai`, **strictement** supérieur. Un préavis envoyé **exactement**
 * `délai` jours avant est donc COUVERT.
 *
 * Ce contrôle refuse donc `délai > période`, **jamais `>=`**. Un `>=` refuserait une configuration
 * qui marche — et **tous ses tests de refus resteraient verts**, plus verts qu'avant. Seul le cas
 * qu'il doit AUTORISER démasque un contrôle trop large ; c'est pour cela que le test de l'égalité
 * a été écrit en premier.
 *
 * ── ⚠ UN MOIS VAUT 28 JOURS ICI, PAS 30 ────────────────────────────────────────────────────────
 *
 * Un mois dure de 28 à 31 jours. Prendre 30 laisserait passer un délai de 29 jours, qui casserait
 * en février — un défaut qui n'apparaîtrait qu'une fois par an, sur un cycle, et que personne ne
 * relierait au paramétrage. On prend le mois le plus court : ce qui passe ici passe toute l'année.
 *
 * ── LES DEUX CÔTÉS, ET AUCUN NE COUVRE L'AUTRE ─────────────────────────────────────────────────
 *
 * Arbitrage de Maxime : les deux emplacements.
 *
 *   sur le CRÉANCIER    refuser un délai qui dépasse la période du plus court abonnement du site
 *                       → attrape le créancier négocié à 30 jours ; `ConfigCreancierSepa` note
 *                         lui-même que « les collectivités négocient souvent plus long »
 *   sur l'ABONNEMENT    refuser une période plus courte que le délai du créancier
 *                       → attrape le premier abonnement hebdomadaire d'un site à 14 jours
 *
 * État au 04/09 : 5 abonnements, tous mensuels ; 4 créanciers, tous à 14 jours. **Latent.**
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class NoticeDelayCoversPeriod extends Constraint
{
    public string $messageCreditor = 'Un préavis de {{ delai }} jours dépasse la période du plus court '
        . 'abonnement de cet établissement ({{ periode }} jours) : chaque échéance serait écartée de la '
        . 'remise, faute d\'un préavis envoyable à temps.';

    public string $messageSubscription = 'Une période de {{ periode }} jours est plus courte que le préavis '
        . 'SEPA de cet établissement ({{ delai }} jours) : chaque échéance de cet abonnement serait '
        . 'écartée de la remise.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
