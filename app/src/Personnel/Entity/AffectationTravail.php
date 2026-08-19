<?php

declare(strict_types=1);

namespace App\Personnel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\State\AffecterEmployeProcessor;
use App\Personnel\State\AnnulerAffectationTravailProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Affectation d'un Employé à un CreneauTravail (RG-PERSO-04, CA-4/5). Un même employé ne peut être
 * affecté à deux créneaux chevauchants, y compris entre établissements différents (conflit bloqué à
 * l'affectation, `AffecterEmployeProcessor`). Si le créneau porte une qualification exigée,
 * l'affectation n'est possible que si l'employé détient une `Qualification` valide correspondante.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personnel_affectation_travail')]
#[ORM\UniqueConstraint(name: 'uniq_affectation_creneau_employe', columns: ['creneau_travail_id', 'employe_id'])]
#[ApiResource(
    shortName: 'AffectationTravail',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire') or is_granted('PERM', 'personnel.lire_soi')"),
        new Get(security: "is_granted('PERM', 'personnel.lire') or is_granted('EMPLOYE_SOI', object.getEmploye())"),
        new Post(
            uriTemplate: '/personnel/affectations',
            read: false,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_planning')",
            processor: AffecterEmployeProcessor::class,
        ),
        new Post(
            uriTemplate: '/personnel/affectations/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_planning')",
            processor: AnnulerAffectationTravailProcessor::class,
            normalizationContext: ['groups' => ['affectation_travail:read']],
        ),
    ],
    normalizationContext: ['groups' => ['affectation_travail:read']],
    denormalizationContext: ['groups' => ['affectation_travail:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['creneauTravail' => 'exact', 'employe' => 'exact', 'statut' => 'exact'])]
class AffectationTravail
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['affectation_travail:read', 'roster:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CreneauTravail::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['affectation_travail:read', 'affectation_travail:write'])]
    private ?CreneauTravail $creneauTravail = null;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['affectation_travail:read', 'affectation_travail:write', 'roster:read'])]
    private ?Employe $employe = null;

    #[ORM\ManyToOne(targetEntity: Qualification::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['affectation_travail:read', 'affectation_travail:write', 'roster:read'])]
    private ?Qualification $qualificationUtilisee = null;

    #[ORM\Column(length: 20, enumType: StatutAffectationTravail::class, options: ['default' => 'planifiee'])]
    #[Groups(['affectation_travail:read', 'roster:read'])]
    private StatutAffectationTravail $statut = StatutAffectationTravail::Planifiee;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreneauTravail(): ?CreneauTravail
    {
        return $this->creneauTravail;
    }

    public function setCreneauTravail(?CreneauTravail $creneauTravail): self
    {
        $this->creneauTravail = $creneauTravail;

        return $this;
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

    public function getQualificationUtilisee(): ?Qualification
    {
        return $this->qualificationUtilisee;
    }

    public function setQualificationUtilisee(?Qualification $qualificationUtilisee): self
    {
        $this->qualificationUtilisee = $qualificationUtilisee;

        return $this;
    }

    public function getStatut(): StatutAffectationTravail
    {
        return $this->statut;
    }

    public function setStatut(StatutAffectationTravail $statut): self
    {
        $this->statut = $statut;

        return $this;
    }
}
