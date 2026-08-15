<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\RoleBeneficiaire;
use App\Crm\State\RetirerBeneficiaireProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Rattachement Client↔Famille enrichi (table pivot, US-L5-03, RG-M4-02). Ajout/retrait tracé et
 * réversible : jamais de suppression physique, `dateRetrait` marque la fin de rattachement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_beneficiaire')]
#[ApiResource(
    shortName: 'Beneficiaire',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.lire')"),
        new Get(security: "is_granted('PERM', 'crm.lire')"),
        new Post(
            uriTemplate: '/beneficiaires/{id}/retirer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'crm.famille_gerer')",
            processor: RetirerBeneficiaireProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['beneficiaire:read']],
)]
class Beneficiaire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['beneficiaire:read', 'famille:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Famille::class, inversedBy: 'beneficiaires')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['beneficiaire:read'])]
    private ?Famille $famille = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['beneficiaire:read', 'famille:read'])]
    private ?Client $client = null;

    #[ORM\Column(length: 24, enumType: RoleBeneficiaire::class)]
    #[Groups(['beneficiaire:read', 'famille:read'])]
    private RoleBeneficiaire $role = RoleBeneficiaire::Beneficiaire;

    /** @var list<string>|null ⊂ {recharger_pmv, acheter_pour_famille, recuperer_mineur, entree_seule, activite_encadree} */
    #[ORM\Column(nullable: true)]
    #[Groups(['beneficiaire:read', 'famille:read'])]
    private ?array $autorisations = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['beneficiaire:read', 'famille:read'])]
    private \DateTimeImmutable $dateAjout;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['beneficiaire:read', 'famille:read'])]
    private ?\DateTimeImmutable $dateRetrait = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateAjout = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFamille(): ?Famille
    {
        return $this->famille;
    }

    public function setFamille(?Famille $famille): self
    {
        $this->famille = $famille;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getRole(): RoleBeneficiaire
    {
        return $this->role;
    }

    public function setRole(RoleBeneficiaire $role): self
    {
        $this->role = $role;

        return $this;
    }

    /** @return list<string> */
    public function getAutorisations(): array
    {
        return $this->autorisations ?? [];
    }

    /** @param list<string>|null $autorisations */
    public function setAutorisations(?array $autorisations): self
    {
        $this->autorisations = $autorisations;

        return $this;
    }

    public function retirerAutorisation(string $autorisation): self
    {
        $this->autorisations = array_values(array_filter($this->getAutorisations(), static fn (string $a): bool => $a !== $autorisation));

        return $this;
    }

    public function getDateAjout(): \DateTimeImmutable
    {
        return $this->dateAjout;
    }

    public function getDateRetrait(): ?\DateTimeImmutable
    {
        return $this->dateRetrait;
    }

    public function setDateRetrait(?\DateTimeImmutable $dateRetrait): self
    {
        $this->dateRetrait = $dateRetrait;

        return $this;
    }

    public function estActif(): bool
    {
        return $this->dateRetrait === null;
    }
}
