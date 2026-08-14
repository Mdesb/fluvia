<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\State\PassageExportProvider;
use App\Acces\State\PassageIngestionProcessor;
use App\Acces\State\PassageManuelProcessor;
use App\Acces\State\PassageNonNominatifProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Source unique des passages (RG-ACC-06, écran A-05). Append-only (garde `PassageInalterableListener`,
 * CA-14) : aucune modification/suppression via l'API. Alimente M6 (compta) et M7 (reporting) sans
 * valoriser ni analyser. `cleIdempotence` évite le doublon au rejeu hors-ligne (CA-9/12).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_passage')]
#[ORM\UniqueConstraint(name: 'uniq_passage_cle_idempotence', columns: ['cle_idempotence'])]
#[ApiResource(
    shortName: 'Passage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new GetCollection(
            uriTemplate: '/acces/passages/export',
            security: "is_granted('PERM', 'acces.lire')",
            provider: PassageExportProvider::class,
        ),
        new Post(
            uriTemplate: '/acces/passages',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.ingestion')",
            processor: PassageIngestionProcessor::class,
        ),
        new Post(
            uriTemplate: '/acces/passages/manuel',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.ouvrir_manuel')",
            processor: PassageManuelProcessor::class,
        ),
        new Post(
            uriTemplate: '/acces/passages/non-nominatif',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.superviser') or is_granted('PERM', 'acces.controler')",
            processor: PassageNonNominatifProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['passage:read']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'espace' => 'exact', 'controleur' => 'exact', 'equipement' => 'exact', 'resultat' => 'exact', 'support.identifiant' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['horodatage'])]
class Passage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['passage:read'])]
    private Uuid $id;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['passage:read'])]
    private \DateTimeImmutable $horodatage;

    #[ORM\ManyToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['passage:read'])]
    private ?EspaceAcces $espace = null;

    #[ORM\ManyToOne(targetEntity: Controleur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?Controleur $controleur = null;

    #[ORM\ManyToOne(targetEntity: Equipement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?Equipement $equipement = null;

    #[ORM\ManyToOne(targetEntity: Support::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?Support $support = null;

    #[ORM\ManyToOne(targetEntity: DroitAcces::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?DroitAcces $droit = null;

    #[ORM\Column(length: 12, enumType: SensPassage::class)]
    #[Groups(['passage:read'])]
    private SensPassage $sens = SensPassage::Entree;

    #[ORM\Column(length: 12, enumType: ResultatPassage::class)]
    #[Groups(['passage:read'])]
    private ResultatPassage $resultat = ResultatPassage::Refuse;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['passage:read'])]
    private ?string $motif = null;

    #[ORM\Column(length: 32, enumType: CodeMotifRefus::class, nullable: true)]
    #[Groups(['passage:read'])]
    private ?CodeMotifRefus $codeMotif = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['passage:read'])]
    private bool $origineHorsLigne = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['passage:read'])]
    private bool $enConflit = false;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['passage:read'])]
    private Uuid $cleIdempotence;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['passage:read'])]
    private ?Utilisateur $agent = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['passage:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->horodatage = new \DateTimeImmutable();
        $this->cleIdempotence = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }

    public function getEspace(): ?EspaceAcces
    {
        return $this->espace;
    }

    public function setEspace(?EspaceAcces $espace): self
    {
        $this->espace = $espace;
        if ($espace !== null) {
            $this->etablissement = $espace->getEtablissement();
        }

        return $this;
    }

    public function getControleur(): ?Controleur
    {
        return $this->controleur;
    }

    public function setControleur(?Controleur $controleur): self
    {
        $this->controleur = $controleur;

        return $this;
    }

    public function getEquipement(): ?Equipement
    {
        return $this->equipement;
    }

    public function setEquipement(?Equipement $equipement): self
    {
        $this->equipement = $equipement;

        return $this;
    }

    public function getSupport(): ?Support
    {
        return $this->support;
    }

    public function setSupport(?Support $support): self
    {
        $this->support = $support;

        return $this;
    }

    public function getDroit(): ?DroitAcces
    {
        return $this->droit;
    }

    public function setDroit(?DroitAcces $droit): self
    {
        $this->droit = $droit;

        return $this;
    }

    public function getSens(): SensPassage
    {
        return $this->sens;
    }

    public function setSens(SensPassage $sens): self
    {
        $this->sens = $sens;

        return $this;
    }

    public function getResultat(): ResultatPassage
    {
        return $this->resultat;
    }

    public function setResultat(ResultatPassage $resultat): self
    {
        $this->resultat = $resultat;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getCodeMotif(): ?CodeMotifRefus
    {
        return $this->codeMotif;
    }

    public function setCodeMotif(?CodeMotifRefus $codeMotif): self
    {
        $this->codeMotif = $codeMotif;

        return $this;
    }

    public function isOrigineHorsLigne(): bool
    {
        return $this->origineHorsLigne;
    }

    public function setOrigineHorsLigne(bool $origineHorsLigne): self
    {
        $this->origineHorsLigne = $origineHorsLigne;

        return $this;
    }

    public function isEnConflit(): bool
    {
        return $this->enConflit;
    }

    public function setEnConflit(bool $enConflit): self
    {
        $this->enConflit = $enConflit;

        return $this;
    }

    public function getCleIdempotence(): Uuid
    {
        return $this->cleIdempotence;
    }

    public function setCleIdempotence(Uuid $cleIdempotence): self
    {
        $this->cleIdempotence = $cleIdempotence;

        return $this;
    }

    public function getAgent(): ?Utilisateur
    {
        return $this->agent;
    }

    public function setAgent(?Utilisateur $agent): self
    {
        $this->agent = $agent;

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
}
