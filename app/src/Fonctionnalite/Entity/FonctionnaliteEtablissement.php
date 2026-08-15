<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Fonctionnalite\State\AppliquerPresetProcessor;
use App\Fonctionnalite\State\EtatFonctionnalitesProvider;
use App\Fonctionnalite\State\MettreAJourFonctionnaliteProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Activation/paramétrage d'une capacité (`App\Fonctionnalite\Enum\CapaciteCode`) pour un établissement
 * donné — couche additive de configuration au-dessus des modules existants (aucun module métier n'est
 * modifié par ce module ; `App\Fonctionnalite\Service\Fonctionnalites::estActive()` est un point
 * d'extension à consommer plus tard par les gardes des modules verticaux). Unicité
 * (établissement, capacité).
 *
 * ⚠ Cloisonnement (RG-SOCLE-05) : les 3 opérations ci-dessous portent l'établissement ciblé dans le
 * chemin (`{id}`), potentiellement différent de l'établissement actif transmis par l'en-tête
 * `X-Etablissement` qu'utilise l'expression `security:` déclarative habituelle (`PermissionVoter`).
 * La vérification de droit est donc faite de façon **impérative**, liée à l'établissement du chemin,
 * par `App\Fonctionnalite\Security\GardeFonctionnaliteEtablissement` dans chaque Provider/Processor
 * (même patron que `App\Crm\State\ResolutionClientSoiTrait`) ; `security:` ne fait ici qu'exiger une
 * authentification.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fonctionnalite_etablissement')]
#[ORM\UniqueConstraint(name: 'uniq_fonctionnalite_etablissement_capacite', columns: ['etablissement_id', 'capacite_code'])]
#[ApiResource(
    shortName: 'FonctionnaliteEtablissement',
    operations: [
        new GetCollection(
            uriTemplate: '/etablissements/{id}/fonctionnalites',
            paginationEnabled: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: EtatFonctionnalitesProvider::class,
        ),
        new Patch(
            uriTemplate: '/etablissements/{id}/fonctionnalites',
            read: false,
            input: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            processor: MettreAJourFonctionnaliteProcessor::class,
        ),
        new Post(
            uriTemplate: '/etablissements/{id}/appliquer-preset',
            read: false,
            input: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            processor: AppliquerPresetProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['fonctionnalite:read']],
    denormalizationContext: ['groups' => ['fonctionnalite:write']],
)]
class FonctionnaliteEtablissement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['fonctionnalite:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 60, name: 'capacite_code')]
    #[Groups(['fonctionnalite:read'])]
    private string $capaciteCode = '';

    #[ORM\Column]
    #[Groups(['fonctionnalite:read'])]
    private bool $active = false;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['fonctionnalite:read'])]
    private ?array $parametres = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['fonctionnalite:read'])]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->modifieLe = new \DateTimeImmutable();
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

    public function getCapaciteCode(): string
    {
        return $this->capaciteCode;
    }

    public function setCapaciteCode(string $capaciteCode): self
    {
        $this->capaciteCode = $capaciteCode;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getParametres(): ?array
    {
        return $this->parametres;
    }

    /** @param array<string, mixed>|null $parametres */
    public function setParametres(?array $parametres): self
    {
        $this->parametres = $parametres;

        return $this;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function toucherModifieLe(): self
    {
        $this->modifieLe = new \DateTimeImmutable();

        return $this;
    }
}
