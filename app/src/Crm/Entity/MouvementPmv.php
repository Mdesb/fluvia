<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Enum\CanalMouvementPmv;
use App\Crm\Enum\TypeMouvementPmv;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Mouvement PMV (RG-M4-03/04) : journal **append-only** (garde `InalterabiliteCrmListener`), preuve
 * de chaque variation de solde (recharge, débit vente, remboursement, expiration, ajustement).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_mouvement_pmv')]
#[ORM\Index(name: 'idx_mouvement_pmv_pmv_date', columns: ['pmv_id', 'date_mouvement'])]
class MouvementPmv
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['pmv:read', 'mouvement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PorteMonnaieVirtuel::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement:read'])]
    private ?PorteMonnaieVirtuel $pmv = null;

    #[ORM\Column(length: 24, enumType: TypeMouvementPmv::class)]
    #[Groups(['pmv:read', 'mouvement:read'])]
    private TypeMouvementPmv $type;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['pmv:read', 'mouvement:read'])]
    private string $montant = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['pmv:read', 'mouvement:read'])]
    private string $soldeApres = '0.00';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['pmv:read', 'mouvement:read'])]
    private \DateTimeImmutable $dateMouvement;

    #[ORM\Column(length: 12, enumType: CanalMouvementPmv::class, nullable: true)]
    #[Groups(['pmv:read', 'mouvement:read'])]
    private ?CanalMouvementPmv $canal = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['pmv:read', 'mouvement:read'])]
    private ?Uuid $refVenteM2 = null;

    /**
     * RG-SOCLE-07 : requis pour un mouvement déclenché par un utilisateur ; laissé `null` uniquement
     * pour un mouvement **système** (expiration planifiée, fusion) — ⚠ HYPOTHÈSE d'adaptation, le
     * cahier ne prévoit pas d'acteur « système » dédié.
     */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mouvement:read'])]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['pmv:read', 'mouvement:read'])]
    private ?string $motif = null;

    public function __construct(TypeMouvementPmv $type = TypeMouvementPmv::Ajustement)
    {
        $this->id = Uuid::v4();
        $this->type = $type;
        $this->dateMouvement = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPmv(): ?PorteMonnaieVirtuel
    {
        return $this->pmv;
    }

    public function setPmv(?PorteMonnaieVirtuel $pmv): self
    {
        $this->pmv = $pmv;

        return $this;
    }

    public function getType(): TypeMouvementPmv
    {
        return $this->type;
    }

    public function setType(TypeMouvementPmv $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getSoldeApres(): string
    {
        return $this->soldeApres;
    }

    public function setSoldeApres(string $soldeApres): self
    {
        $this->soldeApres = $soldeApres;

        return $this;
    }

    public function getDateMouvement(): \DateTimeImmutable
    {
        return $this->dateMouvement;
    }

    public function setDateMouvement(\DateTimeImmutable $dateMouvement): self
    {
        $this->dateMouvement = $dateMouvement;

        return $this;
    }

    public function getCanal(): ?CanalMouvementPmv
    {
        return $this->canal;
    }

    public function setCanal(?CanalMouvementPmv $canal): self
    {
        $this->canal = $canal;

        return $this;
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

    public function getRefVenteM2(): ?Uuid
    {
        return $this->refVenteM2;
    }

    public function setRefVenteM2(?Uuid $refVenteM2): self
    {
        $this->refVenteM2 = $refVenteM2;

        return $this;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }
}
