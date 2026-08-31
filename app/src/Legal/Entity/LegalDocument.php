<?php

declare(strict_types=1);

namespace App\Legal\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Legal\Enum\LegalDocumentType;
use App\Legal\State\EstablishmentStampProcessor;
use App\Legal\State\PublishLegalDocumentProcessor;
use App\Legal\State\PublicLegalDocumentProvider;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * UN DOCUMENT LÉGAL PUBLIÉ, ET SES VERSIONS ANTÉRIEURES.
 *
 * **Pourquoi le versionnement n'est pas un confort.** Des CGV ne sont opposables que dans la version
 * que le client a pu lire **au moment où il a payé**. Un exploitant qui les modifie en mars ne peut pas
 * s'en prévaloir pour une commande de janvier — et sans conservation, il n'a même pas de quoi montrer
 * ce que disait la version de janvier. Le texte publié est donc **figé** : une modification crée une
 * nouvelle version, elle n'écrase jamais la précédente.
 *
 * C'est le même raisonnement que le scellement NF525, appliqué à du texte : *ce qui engage se conserve
 * tel qu'il engageait.*
 *
 * **Ce qui manque encore, et qu'il faut savoir.** `PanierEnLigne` horodate le consentement RGPD
 * (`consentementRgpdHorodatage`) mais **n'enregistre aucune version de CGV acceptée**. Le versionnement
 * ci-dessous rend la preuve *possible* ; il ne la constitue pas tant que le tunnel de commande n'écrit
 * pas la version acceptée sur le panier. Signalé le 27/08, non fait.
 *
 * > **Conserver le texte sans conserver ce que le client a vu, c'est archiver la moitié de la preuve.**
 */
#[ORM\Entity]
#[ORM\Table(name: 'legal_document')]
#[ORM\Index(name: 'idx_legal_document_lookup', columns: ['establishment_id', 'type', 'status'])]
#[ApiResource(
    shortName: 'LegalDocument',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'boutique.gerer_vitrine')"),
        new Get(security: "is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'boutique.gerer_vitrine')"),
        new Post(
            security: "is_granted('PERM', 'organisation.gerer')",
            denormalizationContext: ['groups' => ['legal_document:write']],
            processor: EstablishmentStampProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'organisation.gerer')",
            denormalizationContext: ['groups' => ['legal_document:write']],
        ),
        new Post(
            uriTemplate: '/legal/documents/{id}/publier',
            read: true,
            input: false,
            security: "is_granted('PERM', 'organisation.gerer')",
            processor: PublishLegalDocumentProcessor::class,
        ),
        // LA SEULE OPÉRATION PUBLIQUE, ET ELLE NE REND QUE DU PUBLIÉ.
        //
        // Le visiteur d'une boutique n'a pas de jeton : les documents doivent être lisibles sans
        // compte, sinon les mentions légales ne sont pas « aisément accessibles » au sens de la LCEN.
        // Le fournisseur écarte les brouillons — un brouillon publié par erreur engagerait autant
        // qu'un texte validé.
        new GetCollection(
            uriTemplate: '/legal/publics/{establishmentId}',
            // La variable d'URL doit etre DECLAREE, sinon API Platform repond 404 << Invalid uri
            // variables >> -- une erreur qui se lit comme << cet etablissement n'existe pas >> alors
            // que c'est la route qui est mal formee. Meme patron que
            // `OptionProduit::options-disponibles` : un placeholder distinct de `id`, lie a la classe
            // qu'il designe.
            uriVariables: [
                'establishmentId' => new Link(fromClass: Etablissement::class, identifiers: ['id']),
            ],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: PublicLegalDocumentProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['legal_document:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact', 'status' => 'exact'])]
class LegalDocument
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['legal_document:read'])]
    private Uuid $id;

    /** Pose par `EstablishmentStampProcessor`, jamais par le corps de la requete (D41). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['legal_document:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 40, enumType: LegalDocumentType::class)]
    #[Groups(['legal_document:read', 'legal_document:write'])]
    private LegalDocumentType $type = LegalDocumentType::LegalNotice;

    #[ORM\Column(length: 200)]
    #[Groups(['legal_document:read', 'legal_document:write'])]
    private string $title = '';

    /** Le texte, en Markdown. Rendu côté client : aucun HTML n'est stocké, donc rien à assainir. */
    #[ORM\Column(type: 'text')]
    #[Groups(['legal_document:read', 'legal_document:write'])]
    private string $content = '';

    #[ORM\Column(length: 20)]
    #[Groups(['legal_document:read'])]
    private string $status = self::STATUS_DRAFT;

    /** Numéro de version, incrémenté à chaque publication. La v1 est la première publiée, pas le brouillon. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['legal_document:read'])]
    private int $version = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['legal_document:read'])]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['legal_document:read'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * Ce que le générateur n'a pas pu remplir, nommé champ par champ.
     *
     * Stocké plutôt que recalculé à l'affichage, parce qu'il décrit **l'état du texte tel qu'il a été
     * écrit** : un exploitant qui complète sa fiche d'identité après coup doit régénérer, et la liste
     * doit continuer de dire ce qui manquait dans CE texte-ci tant qu'il n'a pas été régénéré.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['legal_document:read'])]
    private array $missingFields = [];

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getType(): LegalDocumentType
    {
        return $this->type;
    }

    public function setType(LegalDocumentType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<string> */
    public function getMissingFields(): array
    {
        return $this->missingFields;
    }

    /** @param list<string> $missingFields */
    public function setMissingFields(array $missingFields): self
    {
        $this->missingFields = array_values($missingFields);

        return $this;
    }
}
