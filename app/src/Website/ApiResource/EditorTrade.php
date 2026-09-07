<?php

declare(strict_types=1);

namespace App\Website\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Website\State\EditorWebsiteProcessor;
use App\Website\State\EditorWebsiteProvider;

/**
 * Un métier du référentiel, côté administration.
 *
 * ⚠ **C'EST L'ÉCRAN QUI REND LE LOT VRAI.** Tout ce qui précède rend un métier créé en base
 * pleinement servi par le site — sa page, ses modules déduits, son texte, son plan du site. Sans ces
 * opérations, la seule façon de créer ce métier reste une requête SQL à la main : « ajouter un métier
 * sans déploiement » serait vrai pour la machine et faux pour la personne.
 *
 * ── CE QU'ON PEUT ÉCRIRE, ET CE QU'ON NE PEUT PAS ──────────────────────────────────────────────
 *
 * `code` est posé à la création et ne bouge plus : il compose la clé du bloc de texte
 * (`metier.<code>.body`). Le changer détacherait la page de son propre texte, sans erreur — le texte
 * resterait en base, la page s'afficherait vide, et personne ne ferait le lien.
 *
 * `slug` est gelé dès que le métier est PUBLIÉ, pour la même raison que l'adresse d'un article : les
 * liens déjà partagés et les pages déjà indexées pointeraient dans le vide.
 *
 * `activities` se remplace en entier, et chaque valeur doit être l'une des neuf de D15. Une activité
 * inventée est refusée en nommant les neuf — un message qui dit seulement « valeur invalide » oblige
 * à aller lire le code pour connaître la liste.
 *
 * ── CE QU'ON NE TROUVERA PAS ICI ───────────────────────────────────────────────────────────────
 *
 * Ni le corps de la page — il vit dans `/editor/website/blocks`, sous `metier.<code>.body`, avec tous
 * les autres textes — ni les modules : ils se DÉDUISENT des activités et ne se saisissent pas. Un
 * champ « modules » ici permettrait de vendre un module que l'établissement ne peut pas utiliser.
 */
#[ApiResource(
    shortName: 'EditorTrade',
    operations: [
        new GetCollection(uriTemplate: '/editor/website/trades', provider: EditorWebsiteProvider::class),
        new Post(uriTemplate: '/editor/website/trades', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
        new Get(uriTemplate: '/editor/website/trades/{id}', provider: EditorWebsiteProvider::class),
        new Patch(uriTemplate: '/editor/website/trades/{id}', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
        new Delete(uriTemplate: '/editor/website/trades/{id}', provider: EditorWebsiteProvider::class, processor: EditorWebsiteProcessor::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorTrade
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    /** La clé technique, posée à la création et immuable ensuite. */
    public string $code = '';

    /** L'adresse publique, gelée dès la publication. */
    public string $slug = '';

    public string $name = '';

    public string $searchTitle = '';

    public string $lead = '';

    public int $position = 0;

    /** `draft` ou `published` — un brouillon existe ici et n'apparaît nulle part sur le site. */
    public string $status = 'draft';

    /**
     * Les activités de l'établissement type, parmi les neuf de D15.
     *
     * @var list<string>
     */
    public array $activities = [];

    /**
     * Les modules que ces activités allument, en lecture seule.
     *
     * ⚠ **DÉDUITS, JAMAIS SAISIS.** C'est ce qui rend l'écran honnête : on coche ce que
     * l'établissement FAIT, et on voit ce que le produit en conclut. Un champ saisissable
     * permettrait d'annoncer un module qu'aucune activité ne justifie — exactement ce que le
     * garde-fou n°55 refuse par ailleurs.
     *
     * @var list<string>
     */
    public array $modules = [];

    /**
     * Vrai quand l'application connaît ce code (`Metier`), c'est-à-dire quand il porte un préréglage
     * en dur. Ces cinq-là ne se suppriment pas : voir {@see \App\Website\Service\TradeEditor}.
     */
    public bool $builtIn = false;
}
