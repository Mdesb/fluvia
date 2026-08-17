<?php

declare(strict_types=1);

namespace App\Support\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Support\Security\ArticleVisibiliteVoter;
use App\Support\State\PieceJointeAideProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pièce jointe/capture d'un `ArticleAide` (§4.6 spec), optionnellement rattachée à une
 * `VersionArticle` précise (historisation visuelle). Écriture réservée au périmètre de l'article
 * parent (vérifié par `PieceJointeAideProcessor`) ; lecture héritée de `ARTICLE_LIRE`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_piece_jointe_aide')]
#[ApiResource(
    shortName: 'PieceJointeAide',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'support.lire') or is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale') or is_granted('PERM', 'support.administrer')"),
        new Get(security: "is_granted('PUBLIC_ACCESS') and is_granted('" . ArticleVisibiliteVoter::ATTRIBUTE . "', object.getArticle())"),
        new Post(security: "is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale')", processor: PieceJointeAideProcessor::class),
        new Delete(security: "is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale')"),
    ],
    normalizationContext: ['groups' => ['piece_jointe_aide:read']],
    denormalizationContext: ['groups' => ['piece_jointe_aide:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['article' => 'exact'])]
class PieceJointeAide
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['piece_jointe_aide:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArticleAide::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['piece_jointe_aide:read', 'piece_jointe_aide:write'])]
    private ?ArticleAide $article = null;

    #[ORM\ManyToOne(targetEntity: VersionArticle::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['piece_jointe_aide:read', 'piece_jointe_aide:write'])]
    private ?VersionArticle $version = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['piece_jointe_aide:read', 'piece_jointe_aide:write'])]
    private string $nomFichier = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['piece_jointe_aide:read', 'piece_jointe_aide:write'])]
    private string $typeMime = '';

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    #[Groups(['piece_jointe_aide:read', 'piece_jointe_aide:write'])]
    private int $taille = 0;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Groups(['piece_jointe_aide:read', 'piece_jointe_aide:write'])]
    private string $url = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['piece_jointe_aide:read'])]
    private \DateTimeImmutable $dateCreation;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArticle(): ?ArticleAide
    {
        return $this->article;
    }

    public function setArticle(?ArticleAide $article): self
    {
        $this->article = $article;

        return $this;
    }

    public function getVersion(): ?VersionArticle
    {
        return $this->version;
    }

    public function setVersion(?VersionArticle $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function getNomFichier(): string
    {
        return $this->nomFichier;
    }

    public function setNomFichier(string $nomFichier): self
    {
        $this->nomFichier = $nomFichier;

        return $this;
    }

    public function getTypeMime(): string
    {
        return $this->typeMime;
    }

    public function setTypeMime(string $typeMime): self
    {
        $this->typeMime = $typeMime;

        return $this;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    public function setTaille(int $taille): self
    {
        $this->taille = $taille;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }
}
