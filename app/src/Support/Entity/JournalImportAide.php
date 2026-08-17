<?php

declare(strict_types=1);

namespace App\Support\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Support\Enum\ResultatImport;
use App\Support\State\ImportExecuterProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Journal append-only d'exécution de l'import doc vivante (RG-SUP-08) — une ligne par fichier par
 * exécution, jamais modifié/supprimé via l'API.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_journal_import_aide')]
#[ApiResource(
    shortName: 'JournalImportAide',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'support.administrer')"),
        new Get(security: "is_granted('PERM', 'support.administrer')"),
        new Post(
            uriTemplate: '/support/import/executer',
            read: false,
            input: false,
            security: "is_granted('PERM', 'support.administrer')",
            processor: ImportExecuterProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['journal_import:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['resultat' => 'exact', 'cleImport' => 'exact'])]
class JournalImportAide
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['journal_import:read'])]
    private Uuid $id;

    #[ORM\Column(length: 500)]
    #[Groups(['journal_import:read'])]
    private string $cheminFichier = '';

    #[ORM\Column(length: 255)]
    #[Groups(['journal_import:read'])]
    private string $cleImport = '';

    #[ORM\Column(length: 64)]
    #[Groups(['journal_import:read'])]
    private string $hashContenu = '';

    #[ORM\Column(length: 10, enumType: ResultatImport::class)]
    #[Groups(['journal_import:read'])]
    private ResultatImport $resultat;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['journal_import:read'])]
    private ?string $messageErreur = null;

    #[ORM\ManyToOne(targetEntity: ArticleAide::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['journal_import:read'])]
    private ?ArticleAide $article = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['journal_import:read'])]
    private \DateTimeImmutable $dateImport;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateImport = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCheminFichier(): string
    {
        return $this->cheminFichier;
    }

    public function setCheminFichier(string $cheminFichier): self
    {
        $this->cheminFichier = $cheminFichier;

        return $this;
    }

    public function getCleImport(): string
    {
        return $this->cleImport;
    }

    public function setCleImport(string $cleImport): self
    {
        $this->cleImport = $cleImport;

        return $this;
    }

    public function getHashContenu(): string
    {
        return $this->hashContenu;
    }

    public function setHashContenu(string $hashContenu): self
    {
        $this->hashContenu = $hashContenu;

        return $this;
    }

    public function getResultat(): ResultatImport
    {
        return $this->resultat;
    }

    public function setResultat(ResultatImport $resultat): self
    {
        $this->resultat = $resultat;

        return $this;
    }

    public function getMessageErreur(): ?string
    {
        return $this->messageErreur;
    }

    public function setMessageErreur(?string $messageErreur): self
    {
        $this->messageErreur = $messageErreur;

        return $this;
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

    public function getDateImport(): \DateTimeImmutable
    {
        return $this->dateImport;
    }
}
