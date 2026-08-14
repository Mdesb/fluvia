<?php

declare(strict_types=1);

namespace App\Securite\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Securite\State\UtilisateurProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Utilisateur authentifiable (US-L0-02). Le mot de passe est haché (RG-SOCLE-06) et n'est JAMAIS exposé.
 * Le verrouillage temporaire s'appuie sur tentativesEchouees + verrouilleJusqua.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sec_utilisateur')]
#[ORM\UniqueConstraint(name: 'uniq_utilisateur_email', columns: ['email'])]
#[ApiResource(
    shortName: 'Utilisateur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'securite.gerer')"),
        new Get(security: "is_granted('PERM', 'securite.gerer')"),
        new Post(
            security: "is_granted('PERM', 'securite.gerer')",
            processor: UtilisateurProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'securite.gerer')",
            processor: UtilisateurProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['utilisateur:read']],
    denormalizationContext: ['groups' => ['utilisateur:write']],
)]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['utilisateur:read', 'me:read', 'affectation:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['utilisateur:read', 'utilisateur:write', 'me:read', 'affectation:read'])]
    private string $email = '';

    /** Mot de passe haché — jamais exposé via l'API. */
    #[ORM\Column(length: 255)]
    private string $motDePasse = '';

    /**
     * Mot de passe en clair fourni en écriture uniquement, haché par UtilisateurProcessor.
     * Non persisté.
     */
    #[Assert\NotBlank(groups: ['utilisateur:create'])]
    #[Groups(['utilisateur:write'])]
    private ?string $motDePasseClair = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['utilisateur:read', 'utilisateur:write', 'me:read'])]
    private string $nom = '';

    #[ORM\Column]
    #[Groups(['utilisateur:read', 'utilisateur:write', 'me:read'])]
    private bool $actif = true;

    /** @var list<string> Rôles de sécurité Symfony (techniques). */
    #[ORM\Column]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private array $rolesSecurite = ['ROLE_USER'];

    #[ORM\Column(options: ['default' => 0])]
    private int $tentativesEchouees = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verrouilleJusqua = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getMotDePasse(): string
    {
        return $this->motDePasse;
    }

    public function setMotDePasse(string $motDePasse): self
    {
        $this->motDePasse = $motDePasse;

        return $this;
    }

    public function getMotDePasseClair(): ?string
    {
        return $this->motDePasseClair;
    }

    public function setMotDePasseClair(?string $motDePasseClair): self
    {
        $this->motDePasseClair = $motDePasseClair;

        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->motDePasseClair = null;
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

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    /** @return list<string> */
    public function getRolesSecurite(): array
    {
        return $this->rolesSecurite;
    }

    /** @param list<string> $rolesSecurite */
    public function setRolesSecurite(array $rolesSecurite): self
    {
        $this->rolesSecurite = $rolesSecurite;

        return $this;
    }

    public function getTentativesEchouees(): int
    {
        return $this->tentativesEchouees;
    }

    public function setTentativesEchouees(int $tentativesEchouees): self
    {
        $this->tentativesEchouees = $tentativesEchouees;

        return $this;
    }

    public function getVerrouilleJusqua(): ?\DateTimeImmutable
    {
        return $this->verrouilleJusqua;
    }

    public function setVerrouilleJusqua(?\DateTimeImmutable $verrouilleJusqua): self
    {
        $this->verrouilleJusqua = $verrouilleJusqua;

        return $this;
    }

    public function estVerrouille(): bool
    {
        return $this->verrouilleJusqua !== null && $this->verrouilleJusqua > new \DateTimeImmutable();
    }

    // --- UserInterface / PasswordAuthenticatedUserInterface ---

    public function getPassword(): string
    {
        return $this->motDePasse;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->rolesSecurite;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }
}
