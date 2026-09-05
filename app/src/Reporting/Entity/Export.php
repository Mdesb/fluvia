<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Reporting\State\TelechargerExportProvider;
use App\Reporting\Entity\Trait\RattachementNiveauInterface;
use App\Reporting\Entity\Trait\RattachementNiveauTrait;
use App\Reporting\Enum\FormatExport;
use App\Reporting\Enum\StatutExport;
use App\Reporting\State\ExportManuelProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Instance générée d'un `RapportPlanifie`, ou export manuel (§1.9 plan-reporting.md). Le
 * téléchargement du fichier passe par `GET /reporting/exports/{id}/telecharger`
 * (`ExportTelechargerController`), pas par cette ressource (qui n'expose que les métadonnées).
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_export')]
#[ApiResource(
    shortName: 'Export',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reporting.lire')"),
        new Get(security: "is_granted('PERM', 'reporting.lire')"),
        new Post(
            uriTemplate: '/reporting/exports',
            read: false,
            input: false,
            security: "is_granted('PERM', 'reporting.lire')",
            processor: ExportManuelProcessor::class,
        ),
        // ⚠ LA MOITIE QUI MANQUAIT. Le fichier etait genere, ecrit sur disque et son chemin
        // enregistre — et aucune route ne permettait de le lire. Meme patron que
        // `/compta/exports/{id}/telecharger` : une operation dediee, son propre groupe de
        // serialisation, et le contenu qui n'apparait QUE la.
        new Get(
            uriTemplate: '/reporting/exports/{id}/telecharger',
            security: "is_granted('PERM', 'reporting.lire')",
            provider: TelechargerExportProvider::class,
            normalizationContext: ['groups' => ['export:read', 'export:telecharger']],
        ),
    ],
    normalizationContext: ['groups' => ['export:read']],
)]
class Export implements RattachementNiveauInterface
{
    use RattachementNiveauTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['export:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RapportPlanifie::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['export:read'])]
    private ?RapportPlanifie $rapportPlanifie = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['export:read'])]
    private ?string $destinataireEmail = null;

    #[ORM\Column(length: 4, enumType: FormatExport::class)]
    #[Groups(['export:read'])]
    private FormatExport $format = FormatExport::Csv;

    /** @var array<string, mixed>|null Filtres Explorateur appliqués (activité/produit/catégorie/canal/période). */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['export:read'])]
    private ?array $axesAppliques = null;

    #[ORM\Column(length: 8, enumType: StatutExport::class)]
    #[Groups(['export:read'])]
    private StatutExport $statut = StatutExport::Genere;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['export:read'])]
    private ?string $cheminStockage = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['export:read'])]
    private ?string $messageErreur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['export:read'])]
    private \DateTimeImmutable $genereLe;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['export:read'])]
    private ?\DateTimeImmutable $envoyeLe = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['export:read'])]
    private ?Utilisateur $demandePar = null;

    /**
     * Le contenu du fichier, encode en base64 — NON PERSISTE.
     *
     * Il n'a pas de colonne : le fichier vit dans le stockage, et `TelechargerExportProvider` le
     * pose ici a la demande. Il n'apparait que dans le groupe `export:telecharger`, donc jamais
     * dans la collection — sans quoi lister vingt exports rendrait vingt fichiers.
     */
    #[Groups(['export:telecharger'])]
    private ?string $contenuBase64 = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->genereLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRapportPlanifie(): ?RapportPlanifie
    {
        return $this->rapportPlanifie;
    }

    public function setRapportPlanifie(?RapportPlanifie $rapportPlanifie): self
    {
        $this->rapportPlanifie = $rapportPlanifie;

        return $this;
    }

    public function getDestinataireEmail(): ?string
    {
        return $this->destinataireEmail;
    }

    public function setDestinataireEmail(?string $destinataireEmail): self
    {
        $this->destinataireEmail = $destinataireEmail;

        return $this;
    }

    public function getFormat(): FormatExport
    {
        return $this->format;
    }

    public function setFormat(FormatExport $format): self
    {
        $this->format = $format;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getAxesAppliques(): ?array
    {
        return $this->axesAppliques;
    }

    /** @param array<string, mixed>|null $axesAppliques */
    public function setAxesAppliques(?array $axesAppliques): self
    {
        $this->axesAppliques = $axesAppliques;

        return $this;
    }

    public function getStatut(): StatutExport
    {
        return $this->statut;
    }

    public function setStatut(StatutExport $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getCheminStockage(): ?string
    {
        return $this->cheminStockage;
    }

    public function setCheminStockage(?string $cheminStockage): self
    {
        $this->cheminStockage = $cheminStockage;

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

    public function getGenereLe(): \DateTimeImmutable
    {
        return $this->genereLe;
    }

    public function getEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function setEnvoyeLe(?\DateTimeImmutable $envoyeLe): self
    {
        $this->envoyeLe = $envoyeLe;

        return $this;
    }

    public function getDemandePar(): ?Utilisateur
    {
        return $this->demandePar;
    }

    public function setDemandePar(?Utilisateur $demandePar): self
    {
        $this->demandePar = $demandePar;

        return $this;
    }

    public function getContenuBase64(): ?string
    {
        return $this->contenuBase64;
    }

    public function setContenuBase64(?string $contenuBase64): self
    {
        $this->contenuBase64 = $contenuBase64;

        return $this;
    }

}
