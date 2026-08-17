<?php

declare(strict_types=1);

namespace App\Patinoire\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Caution\Entity\GrilleRetenue;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\State\ValiderRetenueProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Trace de l'application de la grille de retenue à une location (US-PATIN-04, CA-4, décision actée).
 * Créée *proposée* (montant par défaut de la grille) au retour en casse/non-rendu
 * (`RetournerPatinsProcessor`), puis **validée** (montant confirmé ou modifié, trace comptable
 * `mouvementRegieRef`) via `ValiderRetenueProcessor`. `forcee=true` si le montant validé diffère du
 * montant par défaut proposé par la grille — réservé à `patinoire.forcer_retenue` (garde-fou §3 du
 * plan, pas de Voter dédié). Reste l'entité locale exposée par `/api/patinoire_retenue_cautions`
 * (contrat inchangé) mais **miroir** du mouvement générique `App\Caution\Entity\MouvementCaution`
 * (`mouvementGeneriqueRef`, référence logique non-FK), source de la logique de
 * proposition/validation/garde-fou RG-SOCLE-07 (`App\Caution\Service\GestionCaution`, refactor
 * caution générique). `grilleAppliquee` référence désormais la grille générique (cible
 * `patinoire.patins`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_retenue_caution')]
#[ORM\UniqueConstraint(name: 'uniq_retenue_caution_location', columns: ['location_id'])]
#[ApiResource(
    shortName: 'PatinoireRetenueCaution',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire')"),
        // Corps : { "montantRetenu"?: decimal }
        new Post(
            uriTemplate: '/patinoire/retenues/{id}/valider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'patinoire.gerer_location') or is_granted('PERM', 'patinoire.forcer_retenue')",
            processor: ValiderRetenueProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['retenue:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['location' => 'exact', 'forcee' => 'exact'])]
class RetenueCaution
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['retenue:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: LocationPatins::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['retenue:read'])]
    private ?LocationPatins $location = null;

    #[ORM\ManyToOne(targetEntity: GrilleRetenue::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['retenue:read'])]
    private ?GrilleRetenue $grilleAppliquee = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['retenue:read'])]
    private string $montantRetenu = '0.00';

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['retenue:read'])]
    private ?Uuid $mouvementRegieRef = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['retenue:read'])]
    private ?Utilisateur $agent = null;

    #[ORM\Column(length: 255)]
    #[Groups(['retenue:read'])]
    private string $motif = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['retenue:read'])]
    private \DateTimeImmutable $horodatage;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['retenue:read'])]
    private bool $forcee = false;

    /** Référence logique (non-FK) vers le `MouvementCaution` générique porteur de cette retenue. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['retenue:read'])]
    private ?Uuid $mouvementGeneriqueRef = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLocation(): ?LocationPatins
    {
        return $this->location;
    }

    public function setLocation(?LocationPatins $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function getGrilleAppliquee(): ?GrilleRetenue
    {
        return $this->grilleAppliquee;
    }

    public function setGrilleAppliquee(?GrilleRetenue $grilleAppliquee): self
    {
        $this->grilleAppliquee = $grilleAppliquee;

        return $this;
    }

    public function getMontantRetenu(): string
    {
        return $this->montantRetenu;
    }

    public function setMontantRetenu(string $montantRetenu): self
    {
        $this->montantRetenu = $montantRetenu;

        return $this;
    }

    public function getMouvementRegieRef(): ?Uuid
    {
        return $this->mouvementRegieRef;
    }

    public function setMouvementRegieRef(?Uuid $mouvementRegieRef): self
    {
        $this->mouvementRegieRef = $mouvementRegieRef;

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

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): self
    {
        $this->motif = $motif;

        return $this;
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

    public function isForcee(): bool
    {
        return $this->forcee;
    }

    public function setForcee(bool $forcee): self
    {
        $this->forcee = $forcee;

        return $this;
    }

    public function getMouvementGeneriqueRef(): ?Uuid
    {
        return $this->mouvementGeneriqueRef;
    }

    public function setMouvementGeneriqueRef(?Uuid $mouvementGeneriqueRef): self
    {
        $this->mouvementGeneriqueRef = $mouvementGeneriqueRef;

        return $this;
    }

    /** Une retenue est validée dès qu'une trace comptable régie lui est associée. */
    public function estValidee(): bool
    {
        return $this->mouvementRegieRef !== null;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->location?->getEtablissement();
    }
}
