<?php

declare(strict_types=1);

namespace App\Subscription\Port;

use App\Organisation\Entity\Etablissement;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ce qu'un module sait exporter de sa configuration, et rejouer ailleurs (ED-3, RG-ED-08, D11).
 *
 * **Pourquoi un port et pas un exportateur central.** Le prospect configure ses offres, ses tarifs et
 * ses horaires pendant sa démo ; à la souscription, tout cela doit repartir sur son établissement
 * réel. Écrire cet export dans le module d'abonnement supposerait qu'il connaisse le modèle de
 * données de `Offre`, de `Reservation`, de chaque verticale — et qu'il casse à chacune de leurs
 * évolutions, dans un fichier que leurs auteurs n'ont pas le droit de corriger. Chaque module décrit
 * donc lui-même sa configuration ; l'abonnement ne fait que collecter, ranger et redonner.
 *
 * **Ce qui passe, et ce qui ne passe jamais.** Un instantané porte de la **configuration** : offres,
 * tarifs, horaires, paramètres. Jamais de données personnelles — pas de clients, pas de réservations,
 * pas d'utilisateurs (RG-ED-08). La démo est un bac à sable jetable dont on ne conserve que les
 * réglages, et c'est aussi ce qui évite d'avoir à purger un export au titre du RGPD.
 *
 * **L'instantané doit survivre à sa source.** Il est rangé au moment de la souscription et rejoué
 * plus tard, après que l'établissement de démo a pu être détruit : un export qui ne porterait que des
 * identifiants pointant vers la démo ne rejouerait rien. On exporte des valeurs, pas des références.
 */
#[AutoconfigureTag('subscription.configuration_snapshot')]
interface ConfigurationSnapshotProvider
{
    /**
     * La capacité dont ce fournisseur porte la configuration — un code de `CapaciteCode`.
     *
     * C'est elle qui décide si l'instantané sera rejoué : un client qui n'a pas acheté le module de
     * réservation ne doit pas voir ses créneaux de démo réapparaître, ni le module s'allumer par la
     * bande.
     */
    public function capability(): string;

    /**
     * L'état configuré de ce module sur l'établissement de démo.
     *
     * @return array<string, mixed> structure libre, propre au module, sérialisable en JSON
     */
    public function capture(Etablissement $source): array;

    /**
     * Rejoue cet instantané sur l'établissement fraîchement provisionné.
     *
     * **L'implémentation doit être idempotente.** Le provisionnement est rejouable par construction
     * (RG-ED-05) ; un rejeu qui dupliquerait les offres livrerait au client un catalogue en double le
     * jour où un rappel bancaire arrive deux fois.
     *
     * @param array<string, mixed> $snapshot tel que rendu par {@see capture()}, éventuellement produit
     *                                       par une version antérieure du module
     */
    public function replay(Etablissement $target, array $snapshot): void;
}
