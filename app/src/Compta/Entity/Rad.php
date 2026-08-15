<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Compta\State\RadNonDisponibleProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * RAD (rapport annuel du délégataire, écran M6-08, profil DSP) — **point d'extension non implémenté**
 * (§7.6 du plan) : aucune US-L4 du backlog actuel ne couvre le RAD/redevances (spec §4.8, ⚠ HYPOTHÈSE
 * §9 du plan). Modélisé pour ne pas bloquer un futur lot dédié ; l'API ne renvoie qu'une indication
 * « non disponible dans ce lot » (CA-15, documentation du hors-périmètre).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_rad')]
#[ApiResource(
    shortName: 'Rad',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire_rad')", provider: RadNonDisponibleProvider::class),
        new Get(security: "is_granted('PERM', 'compta.lire_rad')", provider: RadNonDisponibleProvider::class),
    ],
    normalizationContext: ['groups' => ['rad:read']],
)]
class Rad
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['rad:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rad:read'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\ManyToOne(targetEntity: PeriodeComptable::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rad:read'])]
    private ?PeriodeComptable $exercice = null;

    /** @var array<string, mixed>|null non implémenté dans ce lot. */
    #[ORM\Column(nullable: true)]
    #[Groups(['rad:read'])]
    private ?array $compteExploitation = null;

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

    public function getExercice(): ?PeriodeComptable
    {
        return $this->exercice;
    }

    public function setExercice(?PeriodeComptable $exercice): self
    {
        $this->exercice = $exercice;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getCompteExploitation(): ?array
    {
        return $this->compteExploitation;
    }

    /** @param array<string, mixed>|null $compteExploitation */
    public function setCompteExploitation(?array $compteExploitation): self
    {
        $this->compteExploitation = $compteExploitation;

        return $this;
    }
}
