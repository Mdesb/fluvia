<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Fonctionnalite\Dto\DescripteurCapacite;
use App\Fonctionnalite\Service\CatalogueCapacites;

/**
 * Les modules tels que le site public les présente (ED-11).
 *
 * ⚠ **LA SOURCE EST LE CATALOGUE TECHNIQUE, JAMAIS UNE LISTE RECOPIÉE.** Même règle que les prix :
 * une page qui décrit un module que le produit n'a plus, ou qui en oublie un qu'il vend, est un
 * écart que le prospect relève avant nous — et il le relève au pire moment, en démonstration.
 * Ajouter une capacité au catalogue fait donc apparaître sa page ; en retirer une la fait
 * disparaître, du plan du site comme du menu.
 *
 * ⚠ **LES VERTICALES NE SONT PAS DES MODULES, ET C'EST UN ARBITRAGE, PAS UN DÉTAIL.** « Padel, ce
 * n'est pas un module » — Maxime, 01/09 : `padel`, `piscine`, `sport`, `patinoire` et `musee` sont
 * ce qu'un établissement **est**, pas ce qu'il ajoute à la carte. Le catalogue le sait déjà
 * (`estVerticale`) ; leur donner une page « module » présenterait comme achetable ce qui est un
 * préréglage, et le tunnel ne saurait pas le vendre.
 *
 * **L'adresse d'un module est dérivée de son code**, jamais saisie : `controle_acces` devient
 * `/modules/controle-acces`. Un slug saisi à la main serait une seconde vérité à tenir, et la
 * première divergence casserait un lien déjà partagé.
 */
final readonly class ModuleCatalog
{
    /**
     * Les rubriques du catalogue, dans l'ordre où la page les présente.
     *
     * L'ordre n'est pas alphabétique : il suit le parcours d'un exploitant — vendre, accueillir,
     * encaisser, piloter. Une liste alphabétique commencerait par « Accès nocturne », qui n'intéresse
     * presque personne.
     *
     * @var array<string, string>
     */
    private const RUBRIQUES = [
        'vente' => 'Vendre',
        'acces' => 'Contrôler les accès',
        'planning' => 'Organiser les créneaux',
        'finance' => 'Encaisser et facturer',
        'securite' => 'Sécurité et obligations',
        'confort' => 'Services aux visiteurs',
        'metier' => 'Outils spécialisés',
    ];

    public function __construct(private CatalogueCapacites $capacites)
    {
    }

    /**
     * Tous les modules vendables, rubrique par rubrique, dans l'ordre de présentation.
     *
     * @return list<array{cle: string, titre: string, modules: list<array{slug: string, code: string, libelle: string, description: string}>}>
     */
    public function parRubrique(): array
    {
        $parCle = [];

        foreach ($this->modules() as $module) {
            $parCle[$module['categorie']][] = $module;
        }

        $rubriques = [];

        foreach (self::RUBRIQUES as $cle => $titre) {
            if ([] === ($parCle[$cle] ?? [])) {
                continue;
            }

            $rubriques[] = ['cle' => $cle, 'titre' => $titre, 'modules' => $parCle[$cle]];
            unset($parCle[$cle]);
        }

        // ⚠ CE QUI RESTE N'EST PAS JETÉ. Une catégorie ajoutée au catalogue et absente de la table
        // ci-dessus disparaîtrait de la page en silence — un module vendu, invisible du site. On la
        // rend sous son propre code plutôt que de la perdre : c'est laid, et ça se voit.
        foreach ($parCle as $cle => $modules) {
            $rubriques[] = ['cle' => $cle, 'titre' => ucfirst($cle), 'modules' => $modules];
        }

        return $rubriques;
    }

    /**
     * Tous les modules vendables, à plat.
     *
     * @return list<array{slug: string, code: string, libelle: string, description: string, categorie: string}>
     */
    public function modules(): array
    {
        $modules = [];

        foreach ($this->capacites->toutes() as $capacite) {
            if ($capacite->estVerticale) {
                continue;
            }

            $modules[] = $this->versModule($capacite);
        }

        usort($modules, static fn (array $a, array $b): int => strcmp($a['libelle'], $b['libelle']));

        return $modules;
    }

    /** @return array{slug: string, code: string, libelle: string, description: string, categorie: string}|null */
    public function parSlug(string $slug): ?array
    {
        foreach ($this->modules() as $module) {
            if ($module['slug'] === $slug) {
                return $module;
            }
        }

        return null;
    }

    /** La clé du bloc de texte long d'un module — celle que l'écran d'administration remplit. */
    public static function cleDeBloc(string $code): string
    {
        return 'module.'.$code.'.body';
    }

    public static function slugDe(string $code): string
    {
        return str_replace('_', '-', $code);
    }

    /** @return array{slug: string, code: string, libelle: string, description: string, categorie: string} */
    private function versModule(DescripteurCapacite $capacite): array
    {
        return [
            'slug' => self::slugDe($capacite->code),
            'code' => $capacite->code,
            'libelle' => $capacite->libelle,
            'description' => $capacite->description,
            'categorie' => $capacite->categorie,
        ];
    }
}
