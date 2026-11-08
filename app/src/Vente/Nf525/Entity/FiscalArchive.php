<?php

declare(strict_types=1);

namespace App\Vente\Nf525\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Vente\State\CreateFiscalArchiveProcessor;
use App\Vente\State\VerifyFiscalArchiveProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Archive fiscale annuelle d'un établissement (#342, #343) : ventes, lignes, règlements, avoirs,
 * chaîne scellée, arrêtés de journée, clôtures mensuelles et annuelle, et fréquentation de l'année.
 *
 * BOI-TVA-DECLA-30-10-30 (25/03/2026) : § 220 — l'archivage fige les données et leur donne une date
 * certaine, au plus annuellement ou par exercice, avec un dispositif garantissant son intégrité dans le
 * temps ; § 230 — lisible par l'administration, format ouvert, notice en français ; § 250 — conservée
 * six ans.
 *
 * **Ce que l'archive n'est pas : une purge.** Les données actives restent en place (décision prudente,
 * à valider par Maxime, voir la PR) ; l'archive est une copie figée, séparée, empreinte SHA-256 et
 * signature HMAC posées à sa création, vérifiable à tout moment.
 *
 * **Consultable sans toucher aux tables actives** : `summary` porte les totaux par mois (ventes et
 * fréquentation) et par point de vente ; la liste et la fiche ne lisent que lui.
 *
 * @sans-suppression: archive fiscale à conserver six ans (BOI-TVA-DECLA-30-10-30 § 250) ; la supprimer
 *   effacerait la preuve qu'elle existe pour prouver. `InalterabiliteListener` refuse aussi la suppression ORM.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nf525_fiscal_archive')]
// Une archive par établissement et par année : en refaire une produirait deux « vérités » datées
// différemment sur la même année.
#[ORM\UniqueConstraint(name: 'uniq_fiscal_archive_etab_year', columns: ['etablissement_id', 'fiscal_year'])]
#[ApiResource(
    shortName: 'FiscalArchive',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'vente.lire')", order: ['fiscalYear' => 'DESC']),
        new Get(security: "is_granted('PERM', 'vente.lire')"),
        // `uriTemplate` explicite : c'est lui qui rend `input: false` lisible au garde-fou des formats
        // (n°11), qui ne classe que les opérations sur mesure.
        new Post(
            uriTemplate: '/fiscal_archives',
            description: 'Archive l annee civile (#342/#343). Corps : { year?: AAAA }, par defaut l annee precedente. '
                . 'Exige la cloture annuelle de chaque point de vente actif dans l annee.',
            input: false,
            security: "is_granted('PERM', 'vente.fiscal_closing')",
            processor: CreateFiscalArchiveProcessor::class,
        ),
        // Le fichier, en base64 dans le JSON : même idiome que l'export comptable. Droit distinct de la
        // lecture : l'archive contient le détail de toute une année.
        new Get(
            uriTemplate: '/fiscal_archives/{id}/download',
            security: "is_granted('PERM', 'vente.fiscal_closing')",
            normalizationContext: ['groups' => ['fiscal_archive:read', 'fiscal_archive:download']],
        ),
        new Post(
            uriTemplate: '/fiscal_archives/{id}/verify',
            description: 'Verifie l archive : empreinte, signature, fichiers du manifeste, chaine scellee, totaux.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.lire')",
            processor: VerifyFiscalArchiveProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['fiscal_archive:read']],
    // D41 — aucun champ écrivable depuis un corps de requête : l'archive se construit côté serveur, et
    // son établissement vient de la session. Le groupe est vide exprès.
    denormalizationContext: ['groups' => ['fiscal_archive:write']],
)]
class FiscalArchive
{
    public const FORMAT = 'fluvia-fiscal-archive/1';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['fiscal_archive:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    #[ORM\Column]
    #[Groups(['fiscal_archive:read'])]
    private int $fiscalYear = 2000;

    #[ORM\Column(length: 120)]
    #[Groups(['fiscal_archive:read'])]
    private string $fileName = '';

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['fiscal_archive:read'])]
    private int $fileSize = 0;

    /** Empreinte SHA-256 du fichier ZIP, en hexadécimal. */
    #[ORM\Column(length: 64)]
    #[Groups(['fiscal_archive:read'])]
    private string $sha256 = '';

    /** HMAC-SHA256 de l'empreinte, avec la clé de scellement NF525. */
    #[ORM\Column(length: 64)]
    #[Groups(['fiscal_archive:read'])]
    private string $signature = '';

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['fiscal_archive:read'])]
    private int $salesCount = 0;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['fiscal_archive:read'])]
    private string $salesTotal = '0.00';

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['fiscal_archive:read'])]
    private int $attendanceCount = 0;

    /** @var array<string, mixed> Totaux par mois et par point de vente, lisibles sans ouvrir le fichier. */
    #[ORM\Column]
    #[Groups(['fiscal_archive:read'])]
    private array $summary = [];

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['fiscal_archive:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['fiscal_archive:read'])]
    private ?Utilisateur $author = null;

    /**
     * Le fichier, en morceaux (voir `FiscalArchiveFile`). Jamais sérialisé : ni la liste ni la fiche
     * ne le chargent ; seuls le téléchargement et la vérification le lisent.
     *
     * @var Collection<int, FiscalArchiveFile>
     */
    #[ORM\OneToMany(targetEntity: FiscalArchiveFile::class, mappedBy: 'archive', cascade: ['persist'], fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['part' => 'ASC'])]
    private Collection $parts;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->parts = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getFiscalYear(): int
    {
        return $this->fiscalYear;
    }

    public function setFiscalYear(int $fiscalYear): self
    {
        $this->fiscalYear = $fiscalYear;

        return $this;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    /** Pose le fichier et ses preuves d'un seul geste : jamais un contenu sans son empreinte. */
    public function attachFile(string $fileName, string $zip, string $signature): self
    {
        // Un second appel serait un défaut : l'archive est immuable, son fichier ne se remplace pas.
        // Refusé AVANT de toucher aux preuves, pour ne pas laisser une empreinte qui ne dit plus rien.
        if (!$this->parts->isEmpty()) {
            throw new \LogicException("Le fichier d'une archive fiscale ne se remplace pas.");
        }
        $this->fileName = $fileName;
        $this->fileSize = \strlen($zip);
        $this->sha256 = hash('sha256', $zip);
        $this->signature = $signature;
        foreach (str_split($zip, FiscalArchiveFile::PART_SIZE) as $rang => $morceau) {
            $this->parts->add(new FiscalArchiveFile($this, $rang, $morceau));
        }

        return $this;
    }

    /** Le fichier ZIP reconstitué, morceaux dans l'ordre. */
    public function getContent(): string
    {
        $contenu = '';
        foreach ($this->parts as $morceau) {
            $contenu .= $morceau->getContent();
        }

        return $contenu;
    }

    public function getPartCount(): int
    {
        return $this->parts->count();
    }

    #[Groups(['fiscal_archive:download'])]
    public function getContentBase64(): string
    {
        return base64_encode($this->getContent());
    }

    public function getSalesCount(): int
    {
        return $this->salesCount;
    }

    public function setSalesCount(int $salesCount): self
    {
        $this->salesCount = $salesCount;

        return $this;
    }

    public function getSalesTotal(): string
    {
        return $this->salesTotal;
    }

    public function setSalesTotal(string $salesTotal): self
    {
        $this->salesTotal = $salesTotal;

        return $this;
    }

    public function getAttendanceCount(): int
    {
        return $this->attendanceCount;
    }

    public function setAttendanceCount(int $attendanceCount): self
    {
        $this->attendanceCount = $attendanceCount;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return $this->summary;
    }

    /** @param array<string, mixed> $summary */
    public function setSummary(array $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAuthor(): ?Utilisateur
    {
        return $this->author;
    }

    public function setAuthor(?Utilisateur $author): self
    {
        $this->author = $author;

        return $this;
    }
}
