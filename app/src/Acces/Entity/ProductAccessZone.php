<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Filter\ProductRefFilter;
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
 * ⚠ Corollaire D58 : d'où `ProductRefFilter` plutôt qu'un `SearchFilter`, qui rendrait une liste
 * vide sans rien signaler.
 *
 * ── AUCUNE LIGNE = AUCUNE RESTRICTION ───────────────────────────────────────────────────────────
 *
 * Un produit sans déclaration ouvre toutes les zones, comme avant. C'est ce qui rend la
 * fonctionnalité déployable : elle n'existe que là où quelqu'un l'a demandée. Fermer par défaut
 * refuserait des porteurs qui ont payé, sur un mécanisme dont ils ignorent l'existence.
 *
 * ⚠ L'écran doit le DIRE. Une liste vide ne signifie pas « ce produit n'ouvre rien » mais « aucune
 * restriction ». Affichée comme un tableau vide, elle ferait croire à l'exploitant qu'il a tout
 * fermé alors qu'il a tout ouvert.
 *
 * ── NI `Get` UNITAIRE NI `Patch`, ET C'EST UN CHOIX ─────────────────────────────────────────────
 *
 * Une déclaration n'a rien à montrer seule : elle n'existe que dans la liste d'un produit. Et la
 * modifier n'a pas de sens — on en retire une, on en pose une autre. Deux opérations en moins, donc
 * deux cloisonnements en moins à tenir et deux charges utiles en moins à faire évoluer.
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
#[ApiFilter(ProductRefFilter::class)]
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
