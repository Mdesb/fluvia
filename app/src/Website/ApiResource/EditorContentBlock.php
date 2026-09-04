<?php

declare(strict_types=1);

namespace App\Website\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Website\State\EditorWebsiteProcessor;
use App\Website\State\EditorWebsiteProvider;

/**
 * Un bloc de contenu de la page d'accueil, côté administration (ED-10).
 *
 * ⚠ **LA COLLECTION EST CELLE DES BLOCS DÉCLARÉS PAR LE GABARIT, PAS CELLE DES LIGNES EN BASE.** Un
 * bloc jamais rempli apparaît donc, avec une valeur nulle. L'inverse — lister la table — cacherait
 * précisément les blocs qui manquent, c'est-à-dire les seuls sur lesquels il y a quelque chose à
 * faire.
 *
 * **`Put` et pas `Patch`** : un bloc n'a qu'une valeur, et elle se remplace en entier. Une fusion
 * partielle sur une liste de cartes — remplacer la deuxième sans toucher aux autres — demanderait
 * une sémantique d'index que personne n'a demandée et que tout le monde interpréterait autrement.
 *
 * ⚠ **`type` EST EN LECTURE SEULE.** Il est déclaré par le gabarit ({@see \App\Website\Service\HomeBlocks}) :
 * le recevoir du client permettrait d'enregistrer quatre cartes là où la page attend un titre, et la
 * page casserait à la première visite — après l'enregistrement, donc loin de qui l'a fait.
 */
#[ApiResource(
    shortName: 'EditorContentBlock',
    operations: [
        new GetCollection(uriTemplate: '/editor/website/blocks', provider: EditorWebsiteProvider::class),
        // ⚠ SANS CETTE OPERATION D'ITEM, C'EST LA COLLECTION QUI ECHOUE. API Platform fabrique un
        // `@id` par element, et il lui faut une route d'item pour cela : « Unable to generate an IRI
        // for the item of type EditorContentBlock ». Le symptome ne designe donc pas ce qui manque.
        // ⚠ @sans-ecran: aucune interface n'appelle cette lecture d'item, et pourtant elle est
        // INDISPENSABLE : API Platform fabrique l'identifiant (@id) de chaque element d'une
        // collection a partir d'une route d'item. Sans elle, c'est la COLLECTION qui echoue —
        // « Unable to generate an IRI for the item of type … » — et le symptome ne designe donc pas
        // ce qui manque. Mesure faite le 04/09 en la retirant.
        new Get(uriTemplate: '/editor/website/blocks/{id}', provider: EditorWebsiteProvider::class),
        new Put(uriTemplate: '/editor/website/blocks/{id}', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorContentBlock
{
    /** La clé du bloc — `home.hero.title`. C'est elle l'identifiant, il n'y a pas d'UUID. */
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /** `line`, `paragraph`, `items` ou `cards`. Lecture seule. */
    public string $type = 'line';

    /** Ce que l'écran affiche comme nom du bloc. */
    public string $label = '';

    /** Une phrase d'aide, quand la clé seule ne suffit pas à savoir quoi écrire. */
    public string $help = '';

    /**
     * `accueil` ou `modules` — ce qui permet à l'écran de les présenter séparément.
     *
     * Trente-quatre champs dans une seule liste, c'est une liste que personne ne parcourt : le
     * groupe existe pour que le rédacteur trouve la page qu'il veut modifier, pas pour ranger.
     */
    public string $groupe = 'accueil';

    /**
     * La valeur, dans la forme du type : `{text}`, `{items: [...]}` ou `{items: [{title, text}]}`.
     *
     * `null` signifie « jamais rempli » — et la page ne rend rien pour ce bloc. Elle n'invente aucun
     * texte de remplacement : deux vérités, celle de l'écran et celle de la page, ne peuvent pas
     * coexister sans que l'une mente.
     *
     * @var array<int|string, mixed>|null
     */
    public ?array $value = null;
}
