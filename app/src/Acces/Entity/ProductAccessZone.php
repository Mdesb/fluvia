<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Platform\Filter\UuidReferenceFilter;
use App\Acces\State\ProductAccessZoneStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * « CE PRODUIT OUVRE TELLE OU TELLE ZONE » — la déclaration qui manquait.
 *
 * `DroitAcces::$authorisedSpaces` existait, `ValidationPassageHandler` s'en servait, et rien dans le
 * dépôt ne pouvait l'écrire. Toute collection restait vide, donc « ouvre tout », donc la règle ne
 * refusait jamais rien.
 *
 * ── POURQUOI LA DÉCLARATION VIT SUR LE PRODUIT ET NON SUR LE DROIT ──────────────────────────────
 *
 * Un `DroitAcces` naît d'une VENTE. Y porter la règle obligerait à la poser billet par billet, après
 * coup, sur chaque titre vendu — personne ne le ferait. Elle se déclare donc une fois, sur le
 * produit, et `ProductAccessZoneResolver` en dépose une COPIE sur le droit à la projection.
 *
 * La copie n'est pas une commodité : c'est le droit que les terminaux embarquent pour décider HORS
 * LIGNE. Une règle qui ne vivrait que côté produit serait inapplicable par un lecteur déconnecté,
 * c'est-à-dire précisément quand elle compte.
 *
 * ── LA RÉFÉRENCE DE PRODUIT EST UN `Uuid` NU, ET C'EST VOULU (D2) ───────────────────────────────
 *
 * `App\Acces` ne dépend pas de `App\Offre`. Le produit est désigné par son identifiant, jamais par
 * une relation Doctrine — même patron que `DroitAcces::$produitRef`.
 *
 * ⚠ Corollaire D58 : d'où `UuidReferenceFilter` plutôt qu'un `SearchFilter`, qui rendrait une liste
 * vide sans rien signaler.
 *
 * ── AUCUNE LIGNE = AUCUNE PORTE (D87) ───────────────────────────────────────────────────────────
 *
 * Un produit sans déclaration n'ouvre AUCUNE zone : le droit projeté sort sans espace, et
 * `DroitAcces::ouvre()` le refuse depuis le 30/08 (seuls le personnel et la réservation en sont
 * exemptés). Ce bloc a dit l'inverse — « aucune restriction » — jusqu'au 08/10, après la bascule.
 *
 * D'où la garde de publication (`AccessZonePublicationPrerequisite`) : là où le contrôle d'accès
 * est actif, un produit qui émet un titre ne se publie pas sans zone sur chacun de ses sites. Un
 * billet vendu reste connu du contrôle (D86) ; c'est la porte qui reste fermée.
 *
 * ── NI `Get` UNITAIRE NI `Patch`, ET C'EST UN CHOIX ─────────────────────────────────────────────
 *
 * Une déclaration n'a rien à montrer seule : elle n'existe que dans la liste d'un produit. Et la
 * modifier n'a pas de sens — on en retire une, on en pose une autre. Deux opérations en moins, donc
 * deux cloisonnements en moins à tenir et deux charges utiles en moins à faire évoluer.
 *
 * ⚠ LE ROUTEUR MONTRE POURTANT UN `GET /api/product_access_zones/{id}`. IL N'EST PAS EXPOSÉ.
 *
 * API Platform a besoin d'une cible pour les IRI qu'il génère — un `Delete` n'a pas d'URI sans elle —
 * et fabrique donc une opération `NotExposed` quand aucun `Get` n'est déclaré. Elle répond
 * invariablement 404 avec « This route does not aim to be called. », y compris sur un identifiant
 * existant et sans jeton. Vérifié sur des lignes réelles avant d'écrire ces lignes : j'avais d'abord
 * conclu à une faille, et la mesure l'a réfutée.
 *
 * Ne pas « corriger » cela en déclarant un `Get` : ce serait ouvrir une lecture que personne ne
 * demande, et la faire compter au cliquet d'écart.
 */
#[ORM\Entity]
#[ORM\Table(name: 'access_product_zone')]
#[ORM\UniqueConstraint(name: 'uniq_product_zone', columns: ['product_ref', 'space_id'])]
#[ApiResource(
    shortName: 'ProductAccessZone',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        // L'établissement n'est jamais lu du corps : il vient de la session serveur (D41), et le
        // processeur vérifie en plus que la zone fournie lui appartient.
        new Post(security: "is_granted('PERM', 'acces.gerer')", processor: ProductAccessZoneStampProcessor::class),
        // Retirer une zone est le geste symétrique de l'ajouter. Sans lui, une déclaration posée par
        // erreur serait définitive, et la seule issue serait de tout déclarer — ce qui revient
        // exactement à ne rien restreindre.
        new Delete(security: "is_granted('PERM', 'acces.gerer')"),
    ],
    normalizationContext: ['groups' => ['produit_zone:read']],
    denormalizationContext: ['groups' => ['produit_zone:write']],
)]
#[ApiFilter(UuidReferenceFilter::class, properties: ['productRef'])]
class ProductAccessZone
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['produit_zone:read'])]
    private Uuid $id;

    /**
     * Le produit vendu. Référence libre vers `App\Offre\Entity\Produit` (D2).
     */
    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['produit_zone:read', 'produit_zone:write'])]
    private Uuid $productRef;

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(name: 'space_id', nullable: false)]
    #[Groups(['produit_zone:read', 'produit_zone:write'])]
    private EspaceAcces $space;

    /**
     * ⚠ HORS DU GROUPE D'ÉCRITURE, ET HORS DU CONSTRUCTEUR — POUR DEUX RAISONS DISTINCTES.
     *
     * Hors du groupe d'écriture parce qu'un appelant ne doit jamais désigner sa propre frontière :
     * il déclarerait des zones chez le voisin (D41). Il est estampillé depuis la session serveur.
     *
     * Hors du constructeur parce qu'API Platform construit l'objet à partir du CORPS de la requête,
     * avant que le processeur ne s'exécute. Un troisième argument obligatoire qu'aucun champ ne
     * fournit ferait échouer toute création en 400.
     *
     * La propriété reste typée NON NULLABLE : si le processeur ne posait pas la valeur, le `flush()`
     * échouerait bruyamment plutôt que d'écrire une ligne orpheline. Le sens sûr de l'erreur est
     * celui qui refuse.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    private Etablissement $establishment;

    /**
     * Les deux valeurs que le client fournit sont exigées ici plutôt que posées par des setters.
     *
     * Une propriété laissée à `null` sur une colonne NOT NULL passe PHP, passe Doctrine, et échoue au
     * `flush()` — hors de toute validation. Les exiger rend cet état inatteignable au lieu de
     * simplement détectable.
     */
    public function __construct(Uuid $productRef, EspaceAcces $space)
    {
        $this->id = Uuid::v4();
        $this->productRef = $productRef;
        $this->space = $space;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProductRef(): Uuid
    {
        return $this->productRef;
    }

    public function getSpace(): EspaceAcces
    {
        return $this->space;
    }

    public function getEstablishment(): Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }
}
