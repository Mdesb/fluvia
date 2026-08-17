<?php

declare(strict_types=1);

namespace App\Personnel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Personnel\Enum\StatutAbsence;
use App\Personnel\Enum\TypeAbsence;
use App\Personnel\State\DeclarerAbsenceProcessor;
use App\Personnel\State\ValiderAbsenceProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Absence légère (RG-PERSO-05/10, CA-7) : objet de blocage planning/accès, **pas** un système de
 * gestion des congés légaux (paie/solde délégués au SIRH externe). Une absence validée bloque toute
 * nouvelle affectation de l'employé sur sa période ; si elle chevauche une affectation déjà
 * confirmée, une alerte de couverture est levée (pas d'annulation automatique).
 */
#[ORM\Entity]
#[ORM\Table(name: 'personnel_absence')]
#[ApiResource(
    shortName: 'Absence',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'personnel.lire_soi')"),
        new Get(security: "is_granted('PERM', 'personnel.lire') or is_granted('EMPLOYE_SOI', object.getEmploye())"),
        new Post(
            uriTemplate: '/personnel/absences',
            read: false,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_planning') or is_granted('PERM', 'personnel.declarer_absence_soi')",
            processor: DeclarerAbsenceProcessor::class,
        ),
        new Post(
            uriTemplate: '/personnel/absences/{id}/valider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.valider_absence')",
            processor: ValiderAbsenceProcessor::class,
            normalizationContext: ['groups' => ['absence:read']],
        ),
        new Post(
            uriTemplate: '/personnel/absences/{id}/refuser',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.valider_absence')",
            processor: ValiderAbsenceProcessor::class,
            normalizationContext: ['groups' => ['absence:read']],
        ),
    ],
    normalizationContext: ['groups' => ['absence:read']],
    denormalizationContext: ['groups' => ['absence:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['employe' => 'exact', 'type' => 'exact', 'statut' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['debut'])]
class Absence
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['absence:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['absence:read', 'absence:write'])]
    private ?Employe $employe = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    #[Groups(['absence:read', 'absence:write'])]
    private ?\DateTimeImmutable $debut = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    #[Groups(['absence:read', 'absence:write'])]
    private ?\DateTimeImmutable $fin = null;

    #[ORM\Column(length: 10, enumType: TypeAbsence::class)]
    #[Assert\NotNull]
    #[Groups(['absence:read', 'absence:write'])]
    private ?TypeAbsence $type = null;

    #[ORM\Column(length: 10, enumType: StatutAbsence::class, options: ['default' => 'declaree'])]
    #[Groups(['absence:read'])]
    private StatutAbsence $statut = StatutAbsence::Declaree;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['absence:read'])]
    private ?Utilisateur $valideePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['absence:read', 'absence:write'])]
    private ?string $motif = null;

    /**
     * Alerte de couverture (§4.6 spec, CA-7) : vrai si la validation de cette absence a détecté un
     * chevauchement avec une `AffectationTravail` déjà confirmée. Champ **transitoire**, calculé et
     * positionné par `ValiderAbsenceProcessor` au moment de la validation — non persisté (pas de
     * table de suivi dans ce lot, cf. plan §9 risque n°7 sur les alertes).
     */
    #[Groups(['absence:read'])]
    private bool $alerteCouverture = false;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmploye(): ?Employe
    {
        return $this->employe;
    }

    public function setEmploye(?Employe $employe): self
    {
        $this->employe = $employe;

        return $this;
    }

    public function getDebut(): ?\DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(?\DateTimeImmutable $debut): self
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?\DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(?\DateTimeImmutable $fin): self
    {
        $this->fin = $fin;

        return $this;
    }

    public function getType(): ?TypeAbsence
    {
        return $this->type;
    }

    public function setType(?TypeAbsence $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getStatut(): StatutAbsence
    {
        return $this->statut;
    }

    public function setStatut(StatutAbsence $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getValideePar(): ?Utilisateur
    {
        return $this->valideePar;
    }

    public function setValideePar(?Utilisateur $valideePar): self
    {
        $this->valideePar = $valideePar;

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

    public function isAlerteCouverture(): bool
    {
        return $this->alerteCouverture;
    }

    public function setAlerteCouverture(bool $alerteCouverture): self
    {
        $this->alerteCouverture = $alerteCouverture;

        return $this;
    }
}
