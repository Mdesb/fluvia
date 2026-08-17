<?php

declare(strict_types=1);

namespace App\Support\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Securite\Entity\Utilisateur;
use App\Support\Enum\OrigineArticle;
use App\Support\Enum\StatutArticle;
use App\Support\State\HistoriqueArticleProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Snapshot d'un `ArticleAide` (RG-SUP-03) : append-only, jamais modifié/supprimé. Créé
 * systématiquement par `ArticleAideEcritureService::enregistrer()` à chaque création/modification.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_version_article')]
#[ORM\UniqueConstraint(name: 'uniq_version_article_numero', columns: ['article_id', 'numero'])]
#[ApiResource(
    shortName: 'VersionArticle',
    operations: [
        new GetCollection(
            uriTemplate: '/support/articles/{articleId}/historique',
            uriVariables: [
                'articleId' => new Link(fromClass: ArticleAide::class, identifiers: ['id']),
            ],
            security: "is_granted('PERM', 'support.lire') or is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale') or is_granted('PERM', 'support.administrer')",
            provider: HistoriqueArticleProvider::class,
        ),
        new Get(security: "is_granted('PERM', 'support.lire') or is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale') or is_granted('PERM', 'support.administrer')"),
    ],
    normalizationContext: ['groups' => ['version:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['article' => 'exact'])]
class VersionArticle
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['version:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArticleAide::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['version:read'])]
    private ?ArticleAide $article = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['version:read'])]
    private int $numero = 1;

    #[ORM\Column(type: 'text')]
    #[Groups(['version:read'])]
    private string $contenu = '';

    #[ORM\Column(length: 10, enumType: StatutArticle::class)]
    #[Groups(['version:read'])]
    private StatutArticle $statutAuMoment = StatutArticle::Brouillon;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['version:read'])]
    private ?Utilisateur $auteur = null;

    #[ORM\Column(length: 10, enumType: OrigineArticle::class)]
    #[Groups(['version:read'])]
    private OrigineArticle $origine = OrigineArticle::Manuel;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['version:read'])]
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

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function setNumero(int $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;

        return $this;
    }

    public function getStatutAuMoment(): StatutArticle
    {
        return $this->statutAuMoment;
    }

    public function setStatutAuMoment(StatutArticle $statutAuMoment): self
    {
        $this->statutAuMoment = $statutAuMoment;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getOrigine(): OrigineArticle
    {
        return $this->origine;
    }

    public function setOrigine(OrigineArticle $origine): self
    {
        $this->origine = $origine;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }
}
