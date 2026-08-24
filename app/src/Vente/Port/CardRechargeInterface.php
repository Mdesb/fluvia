<?php

declare(strict_types=1);

namespace App\Vente\Port;

use App\Platform\Event\DomainEvent;
use App\Vente\Entity\BilletSupport;

/**
 * Frontière module Accès (L3, hors périmètre L2, RG-CQ1-09) : incrémente le crédit du droit d'accès
 * déjà appairé à ce support (une carte multi-entrées déjà vendue et appairée). Miroir exact de
 * `AppairageAccesInterface` — définie côté `App\Vente`, ne référence aucune entité `App\Acces`
 * (`DomainEvent` est une classe de la plateforme partagée, pas une entité de module).
 *
 * Contrairement à `AppairageAccesInterface::appairer()`, il n'existe pas d'échec « doux » ici : chaque
 * refus (RG-CQ1-07) interrompt la validation de la vente entière — jamais de création silencieuse
 * d'un second droit/support/appairage (RG-CQ1-02).
 *
 * **D7-bis, publication après commit.** L'écriture réussit toujours ou lève une exception HTTP
 * explicite — mais l'implémentation ne publie PAS elle-même l'événement `access.card_recharged` : le
 * bus est synchrone (`SymfonyEventBus`), et l'incrément s'exécute imbriqué dans la transaction externe
 * de `ValiderVenteService::valider()`. Publier depuis l'implémentation publierait donc AVANT le commit
 * racine réel — un abonné synchrone traiterait une recharge que la vente peut encore annuler (rupture
 * de stock d'une ligne suivante, échec du scellement NF525…). L'événement est donc **retourné** ici ;
 * `ValiderVenteService::valider()` le collecte et ne le publie qu'après le retour de sa transaction
 * (donc après le commit réel) — jamais si elle échoue.
 */
interface CardRechargeInterface
{
    /**
     * Recharge le droit d'accès à crédit appairé à ce support ; renvoie l'événement de domaine à
     * publier **après commit** (jamais `null` en pratique — chaque refus lève plutôt une exception
     * HTTP explicite, RG-CQ1-07) ou lève une exception HTTP explicite.
     */
    public function recharge(BilletSupport $support, int $credits): ?DomainEvent;
}
