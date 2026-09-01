<?php

declare(strict_types=1);

namespace App\Import\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Import\Enum\ImportStatus;
use App\Import\Enum\ImportType;
use App\Import\State\AnalyseImportProcessor;
use App\Import\State\ApplyImportProcessor;
use App\Import\State\RevertImportProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Un lot de reprise initiale (SPEC-REPRISE-INITIALE §2).
 *
 * ── UN IMPORT EST UN OBJET, PAS UNE ACTION ──────────────────────────────────────────────────────
 *
 * C'est ce qui rend tenables d'un seul coup les trois décisions de la spécification : refuser un
 * fichier en entier, le prévisualiser avant d'écrire, et pouvoir revenir en arrière. Une action ne
 * porte rien entre deux appels ; un objet porte le fichier, son verdict et ce qu'il a créé.
 *
 * **On généralise `Finance\Treasury\Entity\BankStatementImport`, on n'invente pas un second
 * patron.** Il portait déjà l'empreinte, le contenu, le statut et son message d'erreur ; la seule
 * chose qu'il n'avait pas est la liste complète des lignes fautives, que la décision « tout
 * refuser » rend indispensable — refuser sans dire quelles lignes obligerait l'exploitant à
 * chercher à l'aveugle dans un fichier de plusieurs milliers de lignes.
 *
 * ── LE FICHIER SOURCE EST CONSERVÉ ──────────────────────────────────────────────────────────────
 *
 * Sans lui, un import contesté six mois plus tard ne se rejuge pas : on n'aurait que le résultat,
 * jamais ce qui l'a produit. `contentHash` rend le doublon reconnaissable — deux dépôts du même
 * fichier se voient.
 *
 * ── L'ÉTABLISSEMENT NE VIENT JAMAIS DU FICHIER (D41) ────────────────────────────────────────────
 *
 * Il est estampillé au serveur. ⚠ Une colonne qui désignerait où écrire serait une porte ouverte
 * chez le voisin, et le symptôme — une ligne **en trop** chez quelqu'un d'autre — n'est jamais
 * remonté à un import par celui qui le subit.
 *
 * @sans-ecran: la reprise se pilote en API et en ligne de commande le temps qu'elle fasse ses
 * preuves. La spécification laisse ouvert « écran de reprise, ou ligne de commande d'abord », et
 * D13 demande de n'ouvrir un écran que pour une raison nommée — un écran posé sur un mécanisme qui
 * n'a jamais tourné se refait. Il viendra quand on saura qui accueille les premiers clients ; ce
 * point-là reste à Maxime, pas à moi.
 *
 * @sans-suppression: un lot est une trace, pas une donnée de travail. Le supprimer détruirait le
 * fichier source que la spécification conserve précisément pour rejuger un import contesté six mois
 * plus tard — sans lui on n'a que le résultat, jamais ce qui l'a produit. Ce que le lot a créé se
 * défait par `POST /imports/{id}/revert`, qui laisse la trace en place et refuse dès qu'une ligne a
 * servi. Effacer le lot lui-même reviendrait à effacer la preuve avec l'erreur.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_batch')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'IDX_IMPORT_BATCH_ETAB_STATUS')]
#[ORM\Index(columns: ['establishment_id', 'type'], name: 'IDX_IMPORT_BATCH_ETAB_TYPE')]
#[ORM\UniqueConstraint(name: 'UNIQ_IMPORT_BATCH_ETAB_HASH', columns: ['establishment_id', 'content_hash'])]
#[ApiResource(
    shortName: 'ImportBatch',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'import.read')"),
        new Get(security: "is_granted('PERM', 'import.read')"),

        // Premier temps : analyser et valider. **N'écrit rien en base métier.**
        new Post(
            uriTemplate: '/imports',
            read: false,
            input: false,
            security: "is_granted('PERM', 'import.manage')",
            processor: AnalyseImportProcessor::class,
        ),

        // Second temps : appliquer, en UNE transaction.
        new Post(
            uriTemplate: '/imports/{id}/apply',
            read: true,
            input: false,
            security: "is_granted('PERM', 'import.manage')",
            processor: ApplyImportProcessor::class,
        ),

        // Défaire exactement ce que ce lot a créé — et refuser dès qu'une ligne a servi.
        new Post(
            uriTemplate: '/imports/{id}/revert',
            read: true,
            input: false,
            security: "is_granted('PERM', 'import.manage')",
            processor: RevertImportProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['import_batch:read']],
    // Fermeture déclarée de la dénormalisation : aucune propriété ne porte `import_batch:write`.
    // Les trois opérations lisent le corps brut et sont en `input: false` ; déclarer la fermeture
    // plutôt que de la laisser dépendre de cette option la rend visible sur l'entité, et lisible
    // par le garde-fou n°12 — qui ne sait pas lire les options d'opération.
    denormalizationContext: ['groups' => ['import_batch:write']],
)]
class ImportBatch
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['import_batch:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['import_batch:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 32, enumType: ImportType::class)]
    #[Groups(['import_batch:read'])]
    private ImportType $type = ImportType::Customers;

    #[ORM\Column(length: 16, enumType: ImportStatus::class)]
    #[Groups(['import_batch:read'])]
    private ImportStatus $status = ImportStatus::Pending;

    #[ORM\Column(length: 255)]
    #[Groups(['import_batch:read'])]
    private string $fileName = '';

    #[ORM\Column(length: 128)]
    #[Groups(['import_batch:read'])]
    private string $mimeType = 'text/csv';

    #[ORM\Column]
    #[Groups(['import_batch:read'])]
    private int $fileSize = 0;

    /** Empreinte du contenu : deux dépôts du même fichier se reconnaissent. */
    #[ORM\Column(length: 64)]
    #[Groups(['import_batch:read'])]
    private string $contentHash = '';

    /**
     * Le fichier source, conservé.
     *
     * Pas exposé en lecture d'API : il peut peser plusieurs mégaoctets et porte des données
     * personnelles. On le garde pour rejuger un import contesté, pas pour l'afficher.
     */
    #[ORM\Column(type: 'text')]
    private string $content = '';

    #[ORM\Column]
    #[Groups(['import_batch:read'])]
    private int $rowCount = 0;

    /**
     * Ligne → message, **la liste entière**.
     *
     * ⚠ Pas la première erreur. Refuser un fichier en nommant une seule ligne condamne l'exploitant
     * à autant d'allers-retours qu'il a de fautes ; c'est ce qui fait renoncer à la reprise et
     * ressaisir à la main.
     *
     * @var array<int, string>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['import_batch:read'])]
    private array $errors = [];

    #[ORM\Column]
    #[Groups(['import_batch:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['import_batch:read'])]
    private ?Utilisateur $createdBy = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['import_batch:read'])]
    private ?\DateTimeImmutable $appliedAt = null;

    /** Nombre de lignes réellement créées à l'application. Zéro tant que le lot n'est pas appliqué. */
    #[ORM\Column]
    #[Groups(['import_batch:read'])]
    private int $createdRows = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getType(): ImportType
    {
        return $this->type;
    }

    public function setType(ImportType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function setStatus(ImportStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): self
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function setRowCount(int $rowCount): self
    {
        $this->rowCount = $rowCount;

        return $this;
    }

    /** @return array<int, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @param array<int, string> $errors */
    public function setErrors(array $errors): self
    {
        $this->errors = $errors;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?Utilisateur
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?Utilisateur $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(?\DateTimeImmutable $appliedAt): self
    {
        $this->appliedAt = $appliedAt;

        return $this;
    }

    public function getCreatedRows(): int
    {
        return $this->createdRows;
    }

    public function setCreatedRows(int $createdRows): self
    {
        $this->createdRows = $createdRows;

        return $this;
    }
}
