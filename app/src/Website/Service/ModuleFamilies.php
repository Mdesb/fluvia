<?php

declare(strict_types=1);

namespace App\Website\Service;

/**
 * Les modules rangés par ce qu'ils font faire, pour la page de vente.
 *
 * **Pourquoi ce regroupement n'est pas celui du domaine.** `CatalogueCapacites` porte déjà une
 * `categorie` par capacité — mais c'est un rangement d'ingénierie, et il est inutilisable ici :
 * `metier` en compte dix, `confort` deux, `acces` deux. Une page de tarifs qui affiche « Métier
 * (10) » puis « Confort (2) » ne dit rien à un acheteur.
 *
 * Le découpage ci-dessous est **éditorial** : il répond à « qu'est-ce que ça me fait faire ? ». Il
 * appartient donc au module Website, avec le reste du discours commercial, et non au domaine —
 * changer la façon de vendre ne doit pas toucher au catalogue des capacités.
 *
 * ---
 *
 * ⚠ **UN MODULE SANS FAMILLE NE DOIT JAMAIS DISPARAÎTRE.**
 *
 * Une correspondance écrite à la main vieillit : le jour où une capacité est ajoutée au catalogue,
 * elle n'est pas dans cette table. Le réflexe — ne montrer que ce qu'on sait ranger — produirait le
 * pire défaut possible sur une page de prix : un module vendable, facturé 19 €, **absent de la page
 * qui liste ce qu'on vend**. Personne ne le verrait, puisqu'il ne manquerait nulle part.
 *
 * Tout inconnu tombe donc dans la dernière famille, qui est volontairement la plus large. Le test
 * {@see \App\Tests\Website\ModuleFamiliesTest} vérifie que le compte des modules rangés égale le
 * compte du catalogue — c'est cette égalité, et non la table, qui garantit qu'on n'en perd aucun.
 */
final class ModuleFamilies
{
    /**
     * Les familles, dans l'ordre où la page les montre.
     *
     * La dernière sert de refuge : voir l'avertissement ci-dessus.
     *
     * @var list<array{cle: string, titre: string, propos: string, teinte: string}>
     */
    private const FAMILLES = [
        [
            'cle' => 'vendre',
            'titre' => 'Vendre',
            'propos' => 'Du catalogue à l’encaissement, au guichet comme en ligne.',
            'teinte' => '#27d9d0',
        ],
        [
            'cle' => 'accueillir',
            'titre' => 'Accueillir',
            'propos' => 'Qui entre, quand, et avec quel droit.',
            'teinte' => '#168ceb',
        ],
        [
            'cle' => 'facturer',
            'titre' => 'Facturer',
            'propos' => 'Ce qui transforme une vente en écriture comptable.',
            'teinte' => '#3755e8',
        ],
        [
            'cle' => 'piloter',
            'titre' => 'Piloter',
            'propos' => 'Le reste de l’exploitation : équipes, matériel, communication.',
            'teinte' => '#7a2ee6',
        ],
    ];

    /**
     * Le code de capacité vers la famille qui le vend.
     *
     * Les codes viennent de {@see \App\Fonctionnalite\Enum\CapaciteCode}.
     *
     * @var array<string, string>
     */
    private const RANGEMENT = [
        'boutique_en_ligne' => 'vendre',
        'stock' => 'vendre',
        'agenda' => 'vendre',
        'porte_monnaie' => 'vendre',
        'dining' => 'vendre',

        'reservation' => 'accueillir',
        'controle_acces' => 'accueillir',
        'casiers' => 'accueillir',
        'no_show' => 'accueillir',
        'acces_nocturne' => 'accueillir',
        'lodging' => 'accueillir',
        'stay' => 'accueillir',

        'comptabilite' => 'facturer',
        'sepa' => 'facturer',
        'recouvrement' => 'facturer',
        'finance' => 'facturer',

        'encadrants' => 'piloter',
        'location_materiel' => 'piloter',
        'poss' => 'piloter',
        'social' => 'piloter',
    ];

    /** La famille de refuge, celle qui accueille tout code inconnu. */
    private const REFUGE = 'piloter';

    /**
     * @return list<array{cle: string, titre: string, propos: string, teinte: string}>
     */
    public function familles(): array
    {
        return self::FAMILLES;
    }

    /**
     * La famille d'un code de capacité — jamais `null`, par construction.
     *
     * Un code absent de la table tombe dans le refuge plutôt que nulle part : voir l'avertissement
     * en tête de classe.
     */
    public function pour(string $code): string
    {
        return self::RANGEMENT[$code] ?? self::REFUGE;
    }

    /**
     * La table de rangement, telle que la page la donne au navigateur.
     *
     * `tarifs.js` lit les prix du catalogue réel à l'exécution ; il a besoin de savoir dans quelle
     * colonne poser chaque option. On lui passe donc la correspondance plutôt que de la recopier
     * en JavaScript — deux tables divergeraient au premier module ajouté.
     *
     * @return array{familles: list<array{cle: string, titre: string, propos: string, teinte: string}>, rangement: array<string, string>, refuge: string}
     */
    public function pourLeNavigateur(): array
    {
        return [
            'familles' => self::FAMILLES,
            'rangement' => self::RANGEMENT,
            'refuge' => self::REFUGE,
        ];
    }
}
