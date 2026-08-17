<?php

declare(strict_types=1);

namespace App\Personnel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Personnel\Enum\StatutEmploye;
use App\Personnel\Enum\TypeContrat;
use App\Personnel\Security\EmployeSoiVoter;
use App\Personnel\State\EmployeStatutProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fiche RH légère d'un employé (RG-PERSO-01, CA-1) : identité, poste, contrat, statut. Le lien vers
 * un `Utilisateur` (socle) est **optionnel** (§3/§4.1 spec) — un employé qui n'opère jamais le
 * logiciel (agent d'entretien…) a une fiche autonome, sans connexion possible.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personnel_employe')]
#[ORM\UniqueConstraint(name: 'uniq_employe_matricule', columns: ['matricule'])]
#[ApiResource(
    shortName: 'Employe',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'personnel.lire')"),
        new Get(security: "is_granted('PERM', 'personnel.lire') or is_granted('EMPLOYE_SOI', object)"),
        new Post(security: "is_granted('PERM', 'personnel.gerer_employe')"),
        new Patch(security: "is_granted('PERM', 'personnel.gerer_employe')"),
        new Post(
            uriTemplate: '/personnel/employes/{id}/suspendre',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_employe')",
            processor: EmployeStatutProcessor::class,
            normalizationContext: ['groups' => ['employe:read']],
        ),
        new Post(
            uriTemplate: '/personnel/employes/{id}/reactiver',
            read: true,
            input: false,
            security: "is_granted('PERM', 'personnel.gerer_employe')",
            processor: EmployeStatutProcessor::class,
            normalizationContext: ['groups' => ['employe:read']],
        ),
    ],
    normalizationContext: ['groups' => ['employe:read']],
    denormalizationContext: ['groups' => ['employe:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'poste' => 'partial', 'matricule' => 'exact'])]
class Employe
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['employe:read', 'rattachement:read', 'qualification:read', 'creneau_travail:read', 'affectation_travail:read', 'absence:read', 'badge_staff:read', 'roster:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['employe:read', 'employe:write'])]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['employe:read', 'employe:write', 'affectation_travail:read', 'absence:read', 'badge_staff:read', 'roster:read'])]
    private string $nom = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['employe:read', 'employe:write', 'affectation_travail:read', 'absence:read', 'badge_staff:read', 'roster:read'])]
    private string $prenom = '';

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['employe:read', 'employe:write'])]
    private ?string $matricule = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['employe:read', 'employe:write', 'roster:read'])]
    private string $poste = '';

    #[ORM\Column(length: 16, enumType: TypeContrat::class)]
    #[Assert\NotNull]
    #[Groups(['employe:read', 'employe:write'])]
    private ?TypeContrat $typeContrat = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['employe:read', 'employe:write'])]
    private ?\DateTimeImmutable $dateEntree = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['employe:read', 'employe:write'])]
    private ?\DateTimeImmutable $dateSortie = null;

    #[ORM\Column(length: 10, enumType: StatutEmploye::class, options: ['default' => 'actif'])]
    #[Groups(['employe:read'])]
    private StatutEmploye $statut = StatutEmploye::Actif;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): self
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getMatricule(): ?string
    {
        return $this->matricule;
    }

    public function setMatricule(?string $matricule): self
    {
        $this->matricule = $matricule;

        return $this;
    }

    public function getPoste(): string
    {
        return $this->poste;
    }

    public function setPoste(string $poste): self
    {
        $this->poste = $poste;

        return $this;
    }

    public function getTypeContrat(): ?TypeContrat
    {
        return $this->typeContrat;
    }

    public function setTypeContrat(?TypeContrat $typeContrat): self
    {
        $this->typeContrat = $typeContrat;

        return $this;
    }

    public function getDateEntree(): ?\DateTimeImmutable
    {
        return $this->dateEntree;
    }

    public function setDateEntree(?\DateTimeImmutable $dateEntree): self
    {
        $this->dateEntree = $dateEntree;

        return $this;
    }

    public function getDateSortie(): ?\DateTimeImmutable
    {
        return $this->dateSortie;
    }

    public function setDateSortie(?\DateTimeImmutable $dateSortie): self
    {
        $this->dateSortie = $dateSortie;

        return $this;
    }

    public function getStatut(): StatutEmploye
    {
        return $this->statut;
    }

    public function setStatut(StatutEmploye $statut): self
    {
        $this->statut = $statut;

        return $this;
    }
}
