<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use App\Offre\Enum\RechargeValidityMode;
use App\Offre\Validator as OffreAssert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Carte multi-entrées : stock de N compostages (RG-M1-04). Une promo « 10=12 » crédite des
 * compostages bonus valables jusqu'à l'expiration de la carte (RG-M1-13 / CA-8).
 * Le stock initial de compostages vaut nbCredite.
 *
 * Une recharge (CQ-1) ajoute du crédit sur le droit existant ; ce que cette recharge fait à la
 * validité de la carte se paramètre ici (CQ-7, D26 : `rechargeValidityMode`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'off_carte_multi_entrees')]
#[OffreAssert\CarteCoherente]
class CarteMultiEntrees
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['produit:read', 'carte:read'])]
    private Uuid $id;

    #[ORM\Column]
    #[Assert\Positive(message: 'Le nombre de passages payés doit être un entier strictement positif.')]
    #[Groups(['produit:read', 'produit:write', 'carte:read', 'carte:write'])]
    private int $nbPaye = 1;

    #[ORM\Column]
    #[Assert\Positive(message: 'Le nombre de passages crédités doit être un entier strictement positif.')]
    #[Groups(['produit:read', 'produit:write', 'carte:read', 'carte:write'])]
    private int $nbCredite = 1;

    #[ORM\Column(type: 'dateinterval', nullable: true)]
    #[Groups(['produit:read', 'produit:write', 'carte:read', 'carte:write'])]
    private ?\DateInterval $validiteDuree = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['produit:read', 'produit:write', 'carte:read', 'carte:write'])]
    private ?\DateTimeImmutable $dateButoir = null;

    /**
     * CQ-7 / D26 — ce qu'une recharge fait à l'échéance de la carte. Le défaut livré est la
     * prolongation ; `Keep` est l'option pour l'exploitant qui veut que la validité d'origine tienne.
     * Colonne nommée en anglais (D5) alors que ses voisines sont historiquement en français :
     * le retrofit prendra les anciennes ; il n'y a pas de raison d'en ajouter une neuvième à reprendre.
     */
    #[ORM\Column(name: 'recharge_validity_mode', length: 12, enumType: RechargeValidityMode::class, options: ['default' => 'extend'])]
    #[Groups(['produit:read', 'produit:write', 'carte:read', 'carte:write'])]
    private RechargeValidityMode $rechargeValidityMode = RechargeValidityMode::Extend;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNbPaye(): int
    {
        return $this->nbPaye;
    }

    public function setNbPaye(int $nbPaye): self
    {
        $this->nbPaye = $nbPaye;

        return $this;
    }

    public function getNbCredite(): int
    {
        return $this->nbCredite;
    }

    public function setNbCredite(int $nbCredite): self
    {
        $this->nbCredite = $nbCredite;

        return $this;
    }

    /** Stock initial de compostages = total crédité (RG-M1-04/13). */
    #[Groups(['produit:read', 'carte:read'])]
    public function getStockCompostagesInitial(): int
    {
        return $this->nbCredite;
    }

    public function getValiditeDuree(): ?\DateInterval
    {
        return $this->validiteDuree;
    }

    public function setValiditeDuree(?\DateInterval $validiteDuree): self
    {
        $this->validiteDuree = $validiteDuree;

        return $this;
    }

    public function getDateButoir(): ?\DateTimeImmutable
    {
        return $this->dateButoir;
    }

    public function setDateButoir(?\DateTimeImmutable $dateButoir): self
    {
        $this->dateButoir = $dateButoir;

        return $this;
    }

    public function getRechargeValidityMode(): RechargeValidityMode
    {
        return $this->rechargeValidityMode;
    }

    public function setRechargeValidityMode(RechargeValidityMode $rechargeValidityMode): self
    {
        $this->rechargeValidityMode = $rechargeValidityMode;

        return $this;
    }

    /**
     * D26 — vrai quand une recharge ne doit PAS toucher à l'échéance en cours.
     *
     * Expose la question plutôt que l'énumération : c'est le module qui exécute la recharge
     * (`Acces`) qui interroge l'offre, jamais l'inverse, et il n'a à connaître que la réponse.
     * Ne vaut **que pour une recharge** : à l'émission initiale d'un droit, il n'y a pas
     * d'échéance à conserver, et ce prédicat n'a rien à y dire.
     */
    public function keepsValidityOnRecharge(): bool
    {
        return $this->rechargeValidityMode === RechargeValidityMode::Keep;
    }
}
