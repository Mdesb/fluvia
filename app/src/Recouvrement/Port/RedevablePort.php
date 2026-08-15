<?php

declare(strict_types=1);

namespace App\Recouvrement\Port;

use App\Acces\Entity\DroitAcces;
use App\Organisation\Entity\Etablissement;

/**
 * Port fourni PAR chaque verticale à abonnement (Sport, demain Piscine en régie…) AU moteur de
 * recouvrement partagé (`App\Recouvrement`, refactor extraction depuis App\Sport). Une implémentation
 * par type de contrat (`typeRedevable()`), agrégée par `App\Recouvrement\Service\RedevableRegistry`
 * (même patron que `App\Sepa\Service\CompositeEcheanceSepaSource`). Aucune dépendance retour de
 * `App\Recouvrement` vers une verticale : c'est la verticale qui implémente ce port, jamais l'inverse.
 */
interface RedevablePort
{
    /** Identifiant opaque du type de contrat porté par cette implémentation (ex. `sport.abonnement_fitness`). */
    public function typeRedevable(): string;

    /** Résout le `DroitAcces` L3 actuellement rattaché au contrat désigné par `$referenceRedevable`, si connu. */
    public function droitAcces(string $referenceRedevable): ?DroitAcces;

    /** Résout l'établissement de rattachement du contrat (cloisonnement, RG-SOCLE-05). */
    public function etablissement(string $referenceRedevable): ?Etablissement;

    /** Vrai si l'utilisateur connecté est le titulaire/payeur du contrat (permissions `_soi`). */
    public function estLieA(string $referenceRedevable, mixed $utilisateur): bool;
}
