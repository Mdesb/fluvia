<?php

declare(strict_types=1);

namespace App\Support\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Support\Enum\OrigineArticle;
use App\Support\Enum\PorteeArticle;
use App\Support\Enum\PublicCible;
use App\Support\Enum\StatutArticle;
use App\Support\Security\ArticleVisibiliteVoter;
use App\Support\State\ArticleAideModifierProcessor;
use App\Support\State\ArticleArchiverProcessor;
use App\Support\State\ArticleAideCreerProcessor;
use App\Support\State\ArticlePublicProvider;
use App\Support\State\ArticlePublierProcessor;
use App\Support\State\RechercheArticleAideProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Article d'aide (US-SUP-02/04/05/06, RG-SUP-02/03/04/05) : contenu Markdown, statut
 * brouillon/publié/archivé, ciblage public/portée, rattachement optionnel à un module fonctionnel,
 * origine manuelle ou import doc vivante. `rechercheTexte` (colonne dénormalisée, index FULLTEXT
 * MariaDB en SQL brut §4 plan) maintenue par `ArticleAideEcritureService`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_article_aide')]
#[ApiResource(
    shortName: 'ArticleAide',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'support.lire') or is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale') or is_granted('PERM', 'support.administrer')"),
        new GetCollection(
            uriTemplate: '/support/articles/publics',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: ArticlePublicProvider::class,
            normalizationContext: ['groups' => ['article_public:read']],
        ),
        new GetCollection(
            uriTemplate: '/support/articles/recherche',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: RechercheArticleAideProvider::class,
            normalizationContext: ['groups' => ['article_public:read']],
        ),
        new Get(security: "is_granted('PUBLIC_ACCESS') and is_granted('" . ArticleVisibiliteVoter::ATTRIBUTE . "', object)"),
        new Post(
            security: "is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale')",
            processor: ArticleAideCreerProcessor::class,
            denormalizationContext: ['groups' => ['article:write:create']],
        ),
        new Patch(
            security: "is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale')",
            processor: ArticleAideModifierProcessor::class,
            denormalizationContext: ['groups' => ['article:write:update']],
        ),
        new Post(
            uriTemplate: '/support/articles/{id}/publier',
            read: true,
            input: false,
            security: "is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale')",
            processor: ArticlePublierProcessor::class,
        ),
        new Post(
            uriTemplate: '/support/articles/{id}/archiver',
            read: true,
            input: false,
            security: "is_granted('PERM', 'support.gerer_kb_globale') or is_granted('PERM', 'support.gerer_kb_locale')",
            processor: ArticleArchiverProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['article:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'categorie' => 'exact', 'publicCible' => 'exact', 'portee' => 'exact', 'moduleLie' => 'partial'])]
#[Assert\Callback('validerPortee')]
class ArticleAide
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['article:read', 'article_public:read'])]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Groups(['article:read', 'article_public:read', 'article:write:create', 'article:write:update'])]
    private string $titre = '';

    #[ORM\Column(length: 220, unique: true)]
    #[Groups(['article:read', 'article_public:read'])]
    private string $slug = '';

    #[ORM\ManyToOne(targetEntity: CategorieAide::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['article:read', 'article_public:read', 'article:write:create', 'article:write:update'])]
    private ?CategorieAide $categorie = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['article:read', 'article_public:read', 'article:write:create', 'article:write:update'])]
    private ?string $resume = null;

    #[ORM\Column(type: 'text')]
    #[Groups(['article:read', 'article_public:read', 'article:write:create', 'article:write:update'])]
    private string $contenu = '';

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['article:read', 'article_public:read', 'article:write:create', 'article:write:update'])]
    private ?array $motsCles = null;

    /** Colonne dénormalisée pour l'index FULLTEXT (§2 plan) — jamais exposée en API. */
    #[ORM\Column(type: 'text')]
    private string $rechercheTexte = '';

    #[ORM\Column(length: 10, enumType: StatutArticle::class, options: ['default' => 'brouillon'])]
    #[Groups(['article:read'])]
    private StatutArticle $statut = StatutArticle::Brouillon;

    #[ORM\ManyToOne(targetEntity: VersionArticle::class)]
    #[ORM\JoinColumn(name: 'version_publiee_id', nullable: true)]
    #[Groups(['article:read'])]
    private ?VersionArticle $versionPubliee = null;

    #[ORM\Column(length: 6, enumType: PorteeArticle::class, options: ['default' => 'global'])]
    #[Groups(['article:read', 'article_public:read', 'article:write:create'])]
    private PorteeArticle $portee = PorteeArticle::Global;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['article:read', 'article_public:read', 'article:write:create'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 6, enumType: PublicCible::class)]
    #[Assert\NotNull]
    #[Groups(['article:read', 'article_public:read', 'article:write:create', 'article:write:update'])]
    private ?PublicCible $publicCible = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['article:read', 'article_public:read', 'article:write:create', 'article:write:update'])]
    private ?string $moduleLie = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['article:read'])]
    private ?Utilisateur $auteur = null;

    #[ORM\Column(length: 10, enumType: OrigineArticle::class, options: ['default' => 'manuel'])]
    #[Groups(['article:read'])]
    private OrigineArticle $origine = OrigineArticle::Manuel;

    #[ORM\Column(length: 255, nullable: true, unique: false)]
    #[Groups(['article:read'])]
    private ?string $cleImport = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hashImportCourant = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['article:read', 'article_public:read'])]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['article:read', 'article_public:read'])]
    private \DateTimeImmutable $dateDerniereModification;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateDerniereModification = $this->dateCreation;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;
        if ($this->slug === '') {
            $this->slug = (new AsciiSlugger())->slug($titre)->lower()->toString() . '-' . substr($this->id->toBase32(), 0, 6);
        }

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /** Réservé à la résolution import (clé stable dérivée, §5.1 plan). */
    public function forcerSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getCategorie(): ?CategorieAide
    {
        return $this->categorie;
    }

    public function setCategorie(?CategorieAide $categorie): self
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getResume(): ?string
    {
        return $this->resume;
    }

    public function setResume(?string $resume): self
    {
        $this->resume = $resume;

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

    /** @return list<string>|null */
    public function getMotsCles(): ?array
    {
        return $this->motsCles;
    }

    /** @param list<string>|null $motsCles */
    public function setMotsCles(?array $motsCles): self
    {
        $this->motsCles = $motsCles;

        return $this;
    }

    public function getRechercheTexte(): string
    {
        return $this->rechercheTexte;
    }

    /** Maintenue par `ArticleAideEcritureService` à chaque enregistrement (§2 plan). */
    public function rafraichirRechercheTexte(): void
    {
        $this->rechercheTexte = trim(implode(' ', [
            $this->titre,
            $this->resume ?? '',
            $this->contenu,
            implode(' ', $this->motsCles ?? []),
        ]));
    }

    public function getStatut(): StatutArticle
    {
        return $this->statut;
    }

    public function setStatut(StatutArticle $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getVersionPubliee(): ?VersionArticle
    {
        return $this->versionPubliee;
    }

    public function setVersionPubliee(?VersionArticle $versionPubliee): self
    {
        $this->versionPubliee = $versionPubliee;

        return $this;
    }

    public function getPortee(): PorteeArticle
    {
        return $this->portee;
    }

    public function setPortee(PorteeArticle $portee): self
    {
        $this->portee = $portee;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getPublicCible(): ?PublicCible
    {
        return $this->publicCible;
    }

    public function setPublicCible(?PublicCible $publicCible): self
    {
        $this->publicCible = $publicCible;

        return $this;
    }

    public function getModuleLie(): ?string
    {
        return $this->moduleLie;
    }

    public function setModuleLie(?string $moduleLie): self
    {
        $this->moduleLie = $moduleLie;

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

    public function getCleImport(): ?string
    {
        return $this->cleImport;
    }

    public function setCleImport(?string $cleImport): self
    {
        $this->cleImport = $cleImport;

        return $this;
    }

    public function getHashImportCourant(): ?string
    {
        return $this->hashImportCourant;
    }

    public function setHashImportCourant(?string $hashImportCourant): self
    {
        $this->hashImportCourant = $hashImportCourant;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateDerniereModification(): \DateTimeImmutable
    {
        return $this->dateDerniereModification;
    }

    public function toucherDateModification(): void
    {
        $this->dateDerniereModification = new \DateTimeImmutable();
    }

    /** RG-SUP-04 : `etablissement` requis si `portee=local`, interdit sinon. */
    public function validerPortee(ExecutionContextInterface $context): void
    {
        if ($this->portee === PorteeArticle::Local && $this->etablissement === null) {
            $context->buildViolation('Un article local doit référencer un établissement.')
                ->atPath('etablissement')
                ->addViolation();
        }
        if ($this->portee === PorteeArticle::Global && $this->etablissement !== null) {
            $context->buildViolation('Un article global ne peut pas référencer un établissement.')
                ->atPath('etablissement')
                ->addViolation();
        }
    }
}
