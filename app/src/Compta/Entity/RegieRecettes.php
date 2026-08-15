<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\State\VersementProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Régie de recettes (US-L4-02, RG-REGIE-02, RG-M6-10) : modes autorisés (⊆ acte de régie), plafond
 * d'encaisse (alerte bloquante au dépassement tant qu'aucun versement n'est enregistré), suivi du
 * fonds de caisse.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_regie_recettes')]
#[ApiResource(
    shortName: 'RegieRecettes',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Patch(security: "is_granted('PERM', 'compta.gerer')"),
        new Post(
            uriTemplate: '/compta/regies/{id}/versements',
            read: true,
            input: false,
            security: "is_granted('PERM', 'caisse.versement')",
            processor: VersementProcessor::class,
            output: BordereauVersement::class,
            normalizationContext: ['groups' => ['bordereau:read']],
        ),
    ],
    normalizationContext: ['groups' => ['regie:read']],
    denormalizationContext: ['groups' => ['regie:write']],
)]
class RegieRecettes
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['regie:read', 'bordereau:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['regie:read', 'regie:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['regie:read', 'regie:write'])]
    private string $libelle = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['regie:read', 'regie:write'])]
    private ?string $acteNomination = null;

    /** @var list<string> codes `MoyenPaiement` autorisés (⊆ référentiel actif, RG-M6-10). */
    #[ORM\Column]
    #[Groups(['regie:read', 'regie:write'])]
    private array $modesAutorises = [];

    #[ORM\Column]
    #[Assert\Positive]
    #[Groups(['regie:read', 'regie:write'])]
    private int $plafondEncaisseCentimes = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['regie:read'])]
    private int $soldeEncaisseCentimes = 0;

    #[ORM\Column(length: 16, options: ['default' => 'quotidien'])]
    #[Groups(['regie:read', 'regie:write'])]
    private string $periodiciteVersement = 'quotidien';

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->profilExploitant?->getEtablissementPrincipal();
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getActeNomination(): ?string
    {
        return $this->acteNomination;
    }

    public function setActeNomination(?string $acteNomination): self
    {
        $this->acteNomination = $acteNomination;

        return $this;
    }

    /** @return list<string> */
    public function getModesAutorises(): array
    {
        return $this->modesAutorises;
    }

    /** @param list<string> $modesAutorises */
    public function setModesAutorises(array $modesAutorises): self
    {
        $this->modesAutorises = array_values($modesAutorises);

        return $this;
    }

    public function getPlafondEncaisseCentimes(): int
    {
        return $this->plafondEncaisseCentimes;
    }

    public function setPlafondEncaisseCentimes(int $plafondEncaisseCentimes): self
    {
        $this->plafondEncaisseCentimes = $plafondEncaisseCentimes;

        return $this;
    }

    public function getSoldeEncaisseCentimes(): int
    {
        return $this->soldeEncaisseCentimes;
    }

    public function setSoldeEncaisseCentimes(int $soldeEncaisseCentimes): self
    {
        $this->soldeEncaisseCentimes = max(0, $soldeEncaisseCentimes);

        return $this;
    }

    public function getPeriodiciteVersement(): string
    {
        return $this->periodiciteVersement;
    }

    public function setPeriodiciteVersement(string $periodiciteVersement): self
    {
        $this->periodiciteVersement = $periodiciteVersement;

        return $this;
    }

    /** RG-M6-10 : alerte bloquante dès dépassement du plafond, tant qu'aucun versement n'est enregistré. */
    public function depassePlafond(): bool
    {
        return $this->soldeEncaisseCentimes > $this->plafondEncaisseCentimes;
    }
}
