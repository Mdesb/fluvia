<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use App\Vente\Enum\StatutAppairage;
use App\Vente\Enum\TypeSupport;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Support d'accès émis avec un billet/abonnement (cahier M2-§4). À la validation, le support est
 * appairé aux droits vendus et devient actif ; un échec bloque la remise et est journalisé
 * (RG-M2-04 / CA-12). L'appairage physique et la consommation relèvent du module Accès (L3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vente_billet_support')]
#[ORM\UniqueConstraint(name: 'uniq_billet_support_identifiant', columns: ['identifiant_support'])]
class BilletSupport
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vente:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vente::class, inversedBy: 'supports')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vente $vente = null;

    #[ORM\ManyToOne(targetEntity: LigneVente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['vente:read'])]
    private ?LigneVente $ligne = null;

    #[ORM\Column(length: 12, enumType: TypeSupport::class, options: ['default' => 'billet'])]
    #[Groups(['vente:read'])]
    private TypeSupport $type = TypeSupport::Billet;

    /** Code de support unique et signé HMAC (CA-12) — cf. `App\Vente\Service\GenerateurCodeSupport`. */
    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['vente:read', 'billet:read'])]
    private ?string $identifiantSupport = null;

    #[ORM\Column(length: 12, enumType: StatutAppairage::class, options: ['default' => 'en_attente'])]
    #[Groups(['vente:read'])]
    private StatutAppairage $statutAppairage = StatutAppairage::EnAttente;

    #[ORM\Column(nullable: true)]
    #[Groups(['vente:read'])]
    private ?int $nbCompostages = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVente(): ?Vente
    {
        return $this->vente;
    }

    public function setVente(?Vente $vente): self
    {
        $this->vente = $vente;

        return $this;
    }

    public function getLigne(): ?LigneVente
    {
        return $this->ligne;
    }

    public function setLigne(?LigneVente $ligne): self
    {
        $this->ligne = $ligne;

        return $this;
    }

    public function getType(): TypeSupport
    {
        return $this->type;
    }

    public function setType(TypeSupport $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getIdentifiantSupport(): ?string
    {
        return $this->identifiantSupport;
    }

    public function setIdentifiantSupport(?string $identifiantSupport): self
    {
        $this->identifiantSupport = $identifiantSupport;

        return $this;
    }

    public function getStatutAppairage(): StatutAppairage
    {
        return $this->statutAppairage;
    }

    public function setStatutAppairage(StatutAppairage $statutAppairage): self
    {
        $this->statutAppairage = $statutAppairage;

        return $this;
    }

    public function getNbCompostages(): ?int
    {
        return $this->nbCompostages;
    }

    public function setNbCompostages(?int $nbCompostages): self
    {
        $this->nbCompostages = $nbCompostages;

        return $this;
    }
}
