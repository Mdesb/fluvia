<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use App\Reservation\Entity\Creneau;
use App\Vente\Entity\LigneVente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Satellite `OneToOne` de `App\Vente\Entity\LigneVente` : « LigneCommande » du cahier = la
 * `LigneVente` M2. Porte le créneau (source de la `Reservation` créée à confirmation, §4.8) et les
 * champs personnalisés/bénéficiaire simple copiés du panier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_ligne_commande_meta')]
#[ORM\UniqueConstraint(name: 'uniq_ligne_commande_meta_ligne', columns: ['ligne_vente_id'])]
class LigneCommandeMeta
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: LigneVente::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?LigneVente $ligneVente = null;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Creneau $creneau = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(nullable: true)]
    private ?array $champsPersonnalises = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(nullable: true)]
    private ?array $beneficiaireSimple = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLigneVente(): ?LigneVente
    {
        return $this->ligneVente;
    }

    public function setLigneVente(?LigneVente $ligneVente): self
    {
        $this->ligneVente = $ligneVente;

        return $this;
    }

    public function getCreneau(): ?Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(?Creneau $creneau): self
    {
        $this->creneau = $creneau;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getChampsPersonnalises(): ?array
    {
        return $this->champsPersonnalises;
    }

    /** @param array<string, mixed>|null $champsPersonnalises */
    public function setChampsPersonnalises(?array $champsPersonnalises): self
    {
        $this->champsPersonnalises = $champsPersonnalises;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getBeneficiaireSimple(): ?array
    {
        return $this->beneficiaireSimple;
    }

    /** @param array<string, mixed>|null $beneficiaireSimple */
    public function setBeneficiaireSimple(?array $beneficiaireSimple): self
    {
        $this->beneficiaireSimple = $beneficiaireSimple;

        return $this;
    }
}
