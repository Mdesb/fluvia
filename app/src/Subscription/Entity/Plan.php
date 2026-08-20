<?php

declare(strict_types=1);

namespace App\Subscription\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une formule d'abonnement : le socle qu'un client souscrit, options non comprises (ED-1, RG-ED-03).
 *
 * `includedCapabilities` liste les capacités **comprises dans le prix**. Ce sont les mêmes codes que
 * ceux du catalogue de capacités et des manifestes de modules — il n'existe pas de catalogue
 * commercial distinct du catalogue technique. Deux listes finiraient par diverger, et c'est toujours
 * le client qui découvre l'écart, au pire moment.
 *
 * **Prix en centiemes entiers.** Le projet porte deux conventions : `decimal(10,2)` pour les tarifs
 * affichés, entiers en centimes pour les chaînes de paiement (`Sepa`, `Recouvrement`). On suit la
 * seconde, parce que l'abonnement calcule des **prorata** — et l'arithmétique décimale sur des
 * fractions de mois fabrique des écarts d'un centime qui deviennent des litiges de facturation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_plan')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_plan_code', columns: ['code'])]
class Plan
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Identifiant stable, utilisé en API et dans les contrats — jamais renommé après vente. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z][a-z0-9_]*$/')]
    private string $code = '';

    /** Libellé commercial. Passe par l'i18n côté UI (D5) ; stocké ici pour l'administration. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $label = '';

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $monthlyPriceCents = 0;

    /**
     * Capacités comprises dans la formule.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $includedCapabilities = [];

    /**
     * Une formule retirée de la vente n'est pas supprimée : des abonnements en cours la référencent,
     * et leur historique de facturation doit rester lisible (RG-PLAT-09, même esprit).
     */
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

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

    /** @return list<string> */
    public function getIncludedCapabilities(): array
    {
        return $this->includedCapabilities;
    }

    /** @param list<string> $includedCapabilities */
    public function setIncludedCapabilities(array $includedCapabilities): self
    {
        $this->includedCapabilities = array_values(array_unique($includedCapabilities));

        return $this;
    }

    public function includes(string $capability): bool
    {
        return \in_array($capability, $this->includedCapabilities, true);
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
