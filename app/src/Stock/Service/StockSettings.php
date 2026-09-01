<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\ParametrageStock;
use App\Stock\Enum\MethodeValorisation;

/**
 * Le paramétrage de stock **d'un établissement, y compris quand il n'y en a pas**.
 *
 * ## Pourquoi cette classe existe
 *
 * `ParametrageStock` est facultatif. Quatre endroits du module lisaient donc son absence, et **chacun
 * improvisait son repli** : le stock négatif tombait sur « interdit » (sûr), la méthode de valorisation
 * sur FIFO (neutre), et le seuil d'écart significatif sur « aucun écart n'est significatif » — ce
 * dernier ouvrant en grand un contrôle de sécurité. Deux de ces lectures vivaient dans le même fichier,
 * à vingt lignes d'écart, et tombaient dans des directions opposées.
 *
 * Ce n'est pas une négligence : c'est ce qui arrive quand l'absence de configuration n'a pas de sens
 * **déclaré**. Corriger la lecture fautive aurait laissé la prochaine retomber au hasard.
 *
 * ## La règle, déclarée une fois
 *
 * **L'absence de configuration n'accorde rien et ne désactive aucun contrôle.**
 *
 * Les deux replis paraissent opposés ; ils découlent de la même phrase. `autoriserStockNegatif` est une
 * **permission** : sans décision, elle n'est pas accordée. Le seuil d'écart significatif est un
 * **contrôle** : sans décision, il ne se désactive pas — donc tout écart est significatif. Un exploitant
 * qui n'a rien réglé n'a pas décidé que tout passait ; il n'a rien décidé.
 *
 * La méthode de valorisation est le seul cas différent, et légitimement : ce n'est ni une permission ni
 * un contrôle, mais un choix technique qui doit bien tomber quelque part. FIFO est le défaut déclaré de
 * l'entité elle-même, on le reprend tel quel.
 *
 * ## Le trou qu'on ne voyait pas
 *
 * Le repli ne se déclenchait pas seulement quand `ParametrageStock` manquait. Un paramétrage **présent
 * mais dont les deux seuils sont `null`** produisait exactement le même effet : plus aucun écart
 * significatif. C'est le cas le plus probable en pratique — un exploitant crée son paramétrage pour
 * choisir sa valorisation, et ne remplit jamais les seuils. Ici, les deux situations donnent le même
 * résultat, parce qu'elles disent la même chose : personne n'a fixé de seuil.
 */
final class StockSettings
{
    private function __construct(private readonly ?ParametrageStock $parametrage)
    {
    }

    public static function from(?ParametrageStock $parametrage): self
    {
        return new self($parametrage);
    }

    /** Une permission : sans décision explicite, elle n'est pas accordée. */
    public function allowsNegativeStock(): bool
    {
        return $this->parametrage?->isAutoriserStockNegatif() ?? false;
    }

    /** Ni permission ni contrôle : un choix technique, dont le défaut est celui de l'entité. */
    public function valuationMethod(): MethodeValorisation
    {
        return $this->parametrage?->getMethodeValorisationDefaut() ?? MethodeValorisation::Fifo;
    }

    public function significanceThresholdPercentage(): ?string
    {
        return $this->parametrage?->getSeuilEcartSignificatifPourcentage();
    }

    public function significanceThresholdAmount(): ?string
    {
        return $this->parametrage?->getSeuilEcartSignificatifMontant();
    }

    /**
     * Aucun seuil n'a été fixé — ni par absence de paramétrage, ni par paramétrage laissé vide.
     *
     * Dans ce cas **tout écart non nul est significatif**, et la validation renforcée
     * (`stock.valider_ecart`) s'applique. Le repli sûr d'un contrôle est de contrôler.
     */
    public function noThresholdConfigured(): bool
    {
        return null === $this->significanceThresholdPercentage()
            && null === $this->significanceThresholdAmount();
    }
}
