<?php

declare(strict_types=1);

namespace App\Subscription\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une option facturable — c'est-à-dire **un module vendu à la carte** (RG-ED-03).
 *
 * `capability` est le code de capacité du catalogue : le même que celui qu'un `ModuleManifest`
 * déclare, et le même que celui que `ModuleAccess::hasModule()` interroge. Vendre un module et
 * l'activer manipulent donc littéralement la même donnée. C'est ce qui garantit qu'on ne puisse pas
 * facturer une option qui n'existe pas, ni livrer une capacité que personne n'a payée.
 *
 * Le prix est global, pas par formule : une option coûte le même prix quelle que soit la formule
 * choisie. Si un jour ce n'est plus vrai, ça devient une grille — pas une colonne de plus ici.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_plan_option')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_option_capability', columns: ['capability'])]
class PlanOption
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Code de capacité du catalogue (`controle_acces`, `reservation`…). Unique : une option par module. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z][a-z0-9_]*$/')]
    private string $capability = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $label = '';

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $monthlyPriceCents = 0;

    /** Retirée de la vente, jamais supprimée : des abonnements en cours la référencent. */
    #[ORM\Column]
    private bool $active = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    public function setCapability(string $capability): self
    {
        $this->capability = $capability;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getMonthlyPriceCents(): int
    {
        return $this->monthlyPriceCents;
    }

    public function setMonthlyPriceCents(int $monthlyPriceCents): self
    {
        $this->monthlyPriceCents = $monthlyPriceCents;

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
}
