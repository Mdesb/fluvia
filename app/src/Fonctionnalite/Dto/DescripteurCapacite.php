<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Dto;

/**
 * Fiche descriptive d'une capacité du catalogue (code, libellé, description, catégorie) — donnée de
 * référence, non persistée (cf. `App\Fonctionnalite\Service\CatalogueCapacites`).
 */
final class DescripteurCapacite
{
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly string $description,
        public readonly string $categorie,
        /**
         * ⚠ CETTE CAPACITE EST-ELLE UNE VERTICALE D'ACTIVITE, PLUTOT QU'UN MODULE ACHETABLE ?
         *
         * « Padel, ce n'est pas un module » — Maxime, 01/09. Il a raison : `padel`, `piscine`,
         * `sport`, `patinoire` et `musee` sont des valeurs de l'enum `Metier`. Ce sont des PRESETS
         * — `PresetVerticale` fait correspondre `padel` a un jeu de cinq capacites — donc ce qu'un
         * etablissement EST, pas ce qu'il ajoute a la carte.
         *
         * Elles doivent rester au catalogue : sans elles, `Fonctionnalites::definir()` refuse le
         * code et le module vertical est present et definitivement inaccessible. Mais elles n'ont
         * rien a faire dans une boutique.
         *
         * ⚠ LE SERVEUR LE DIT, L'ECRAN NE LE DEVINE PAS. Filtrer cote frontal sur la categorie
         * « metier » reconstruirait une regle metier a partir d'une etiquette decorative, et les
         * deux divergeraient au premier ajout.
         */
        public readonly bool $estVerticale = false,
        /**
         * ⚠ CE MODULE PEUT-IL SERVIR À QUELQUE CHOSE AUJOURD'HUI ?
         *
         * Trois capacités du catalogue sont **activables et facturées au mois** sans pouvoir rendre
         * le moindre service. Recompté le 04/09 par `bin/garde-fou-modules-non-servables.php`, qui
         * compte les occurrences de `#[ORM\Entity]` et `#[ApiResource]` — contre `padel` pris comme
         * témoin positif (15 entités, 17 ressources, des écrans) :
         *
         *     lodging   0 entité · 0 ressource · 0 écran   il ne peut pas enregistrer une chambre
         *     stay      2 entités · 2 ressources · 0 écran   le serveur existe, personne ne s'en sert
         *     dining    2 entités · 1 ressource · 0 écran   même situation
         *
         * `lodging` ne peut pas enregistrer une seule chambre : ses cinq fichiers sont des classes
         * de domaine pures.
         *
         * ⚠ §8.1 annonçait d'autres chiffres (« stay : 9 entités ») avec un autre comptage — le même
         * qui donne 36 entités à Padel là où les attributs en montrent 15. Je les avais recopiés sans
         * les refaire. **Un chiffre sans sa définition ne se relaie pas** ; ceux-ci portent la leur,
         * et une commande les recompte.
         *
         * **Arbitrage de Maxime, 04/09 : « les rendre non facturables ».** Ils restent au catalogue
         * et gardent leur mention « en construction » — l'exploitant voit ce qui arrive — mais rien
         * ne se vend et rien ne se facture tant que le module ne sert à rien.
         *
         * ⚠ CE N'EST PAS `estVerticale`, ET LES CONFONDRE SERAIT FAUX. Une verticale est exclue de
         * la boutique **par nature** — c'est ce qu'un établissement EST. Celle-ci est exclue
         * **temporairement**, par un état de fait qui cessera. Le jour où le module sert, le drapeau
         * tombe et il se vend ; une verticale, elle, ne se vendra jamais à la carte.
         */
        public readonly bool $peutServir = true,
    ) {
    }
}
