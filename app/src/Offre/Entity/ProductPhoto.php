<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Offre\State\DeleteProductPhotoProcessor;
use App\Offre\State\ProductPhotoListProvider;
use App\Offre\State\UploadProductPhotoProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * UNE PHOTO DE PRODUIT — un lien vers un document, jamais un second système de fichiers.
 *
 * Le fichier vit dans le **DMS**, par `App\Dms\DocumentStore` — un port écrit pour les modules
 * consommateurs et qui n'en avait aucun jusqu'ici. Stocker les images ailleurs aurait donné au dépôt
 * deux endroits où vivent des fichiers, deux façons de les chiffrer, deux politiques de rétention —
 * et une seule des deux aurait été maintenue.
 *
 * La référence est **libre** (`Uuid` nu, D2) : le module Offre ne dépend pas des entités du DMS, et
 * un document effacé ne fait pas disparaître le produit sous lui.
 *
 * ── CE QUE LA BOUTIQUE AFFICHAIT DÉJÀ ───────────────────────────────────────────────────────────
 *
 * Le catalogue public lisait un visuel dans `Produit.champsPerso['visuelUrl']` — un champ JSON
 * libre, sans validation ni nettoyage, qu'il fallait remplir à la main avec l'adresse d'une image
 * hébergée ailleurs. L'AFFICHAGE existait ; c'est le téléversement qui manquait. Les deux chemins
 * cohabitent : une vitrine qui a déjà renseigné une URL continue de fonctionner.
 *
 * ── POURQUOI LE TEXTE ALTERNATIF EST OBLIGATOIRE ────────────────────────────────────────────────
 *
 * Une image sans alternative textuelle est invisible pour un lecteur d'écran et pour un moteur de
 * recherche. Pour un établissement public, ce n'est pas un confort : le RGAA en fait un critère, et
 * les acheteurs publics le vérifient. Un champ facultatif reste vide ; celui-ci est exigé au
 * téléversement, seul instant où quelqu'un sait ce que montre la photo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'offre_product_photo')]
#[ORM\Index(name: 'idx_offre_product_photo_produit', columns: ['produit_id', 'position'])]
#[ApiResource(
    shortName: 'ProductPhoto',
    operations: [
        // LA LISTE D'UN PRODUIT — `{id}` désigne le PRODUIT, pas la photo.
        new Get(
            uriTemplate: '/offre/produits/{id}/photos',
            security: "is_granted('PERM', 'offre.lire')",
            provider: ProductPhotoListProvider::class,
        ),

        // LE TÉLÉVERSEMENT — multipart, corps lu à la main (idiome du dépôt).
        new Post(
            uriTemplate: '/offre/produits/{id}/photos',
            security: "is_granted('PERM', 'offre.modifier')",
            deserialize: false,
            processor: UploadProductPhotoProcessor::class,
        ),

        new Delete(
            uriTemplate: '/offre/photos-produit/{id}',
            security: "is_granted('PERM', 'offre.modifier')",
            processor: DeleteProductPhotoProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['product_photo:read']],
)]
class ProductPhoto
{
    /** Ce qu'un navigateur sait afficher, et rien d'autre. */
    public const TYPES_ACCEPTES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    /** Deux mégaoctets : au-delà, c'est une photo qu'on n'a pas redimensionnée. */
    public const TAILLE_MAX = 2 * 1024 * 1024;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['product_photo:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Produit $produit = null;

    /** Référence libre vers `App\Dms\Entity\Document` (D2). */
    #[ORM\Column(type: UuidType::NAME)]
    private ?Uuid $documentRef = null;

    /** L'ordre d'affichage. La première photo est celle que la boutique montre. */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['product_photo:read'])]
    private int $position = 0;

    #[ORM\Column(length: 160)]
    #[Groups(['product_photo:read'])]
    private string $altText = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['product_photo:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): self
    {
        $this->produit = $produit;

        return $this;
    }

    public function getDocumentRef(): ?Uuid
    {
        return $this->documentRef;
    }

    public function setDocumentRef(?Uuid $documentRef): self
    {
        $this->documentRef = $documentRef;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getAltText(): string
    {
        return $this->altText;
    }

    public function setAltText(string $altText): self
    {
        $this->altText = $altText;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * L'adresse publique de cette photo.
     *
     * Calculée, jamais stockée : une URL recopiée en base survit au changement de route et devient
     * un lien mort que personne ne relie à sa cause.
     */
    #[Groups(['product_photo:read'])]
    public function getUrl(): string
    {
        return '/media/produit/' . $this->id;
    }
}
