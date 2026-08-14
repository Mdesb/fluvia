<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use App\Vente\Enum\StatutTPE;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Règlement d'une vente (RG-M2-02/03). Paiement scindé multi-moyens jusqu'à reste dû = 0 ; le code
 * du moyen est stocké en clair (référentiel M6, pas de FK dure). Le rendu de monnaie n'est possible
 * que sur un moyen autorisant le rendu (espèces, RG-M2-05 / CA-9). La référence TPE est conservée
 * pour les paiements CB (US-L2-07). Immuable une fois la vente validée (NF525).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vente_paiement')]
class Paiement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vente:read', 'paiement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vente::class, inversedBy: 'paiements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vente $vente = null;

    #[ORM\Column(length: 32)]
    #[Groups(['vente:read', 'paiement:read'])]
    private string $moyenCode = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['vente:read', 'paiement:read'])]
    private string $montant = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read', 'paiement:read'])]
    private string $rendu = '0.00';

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['vente:read', 'paiement:read'])]
    private ?string $refTPE = null;

    #[ORM\Column(length: 12, enumType: StatutTPE::class, nullable: true)]
    #[Groups(['vente:read', 'paiement:read'])]
    private ?StatutTPE $statutTPE = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['paiement:read'])]
    private ?string $banque = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['paiement:read'])]
    private ?string $numeroCheque = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['vente:read', 'paiement:read'])]
    private bool $differe = false;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['vente:read', 'paiement:read'])]
    private \DateTimeImmutable $dateHeure;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateHeure = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): self
    {
        $this->id = $id;

        return $this;
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

    public function getMoyenCode(): string
    {
        return $this->moyenCode;
    }

    public function setMoyenCode(string $moyenCode): self
    {
        $this->moyenCode = $moyenCode;

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

    public function getRendu(): string
    {
        return $this->rendu;
    }

    public function setRendu(string $rendu): self
    {
        $this->rendu = $rendu;

        return $this;
    }

    public function getRefTPE(): ?string
    {
        return $this->refTPE;
    }

    public function setRefTPE(?string $refTPE): self
    {
        $this->refTPE = $refTPE;

        return $this;
    }

    public function getStatutTPE(): ?StatutTPE
    {
        return $this->statutTPE;
    }

    public function setStatutTPE(?StatutTPE $statutTPE): self
    {
        $this->statutTPE = $statutTPE;

        return $this;
    }

    public function getBanque(): ?string
    {
        return $this->banque;
    }

    public function setBanque(?string $banque): self
    {
        $this->banque = $banque;

        return $this;
    }

    public function getNumeroCheque(): ?string
    {
        return $this->numeroCheque;
    }

    public function setNumeroCheque(?string $numeroCheque): self
    {
        $this->numeroCheque = $numeroCheque;

        return $this;
    }

    public function isDiffere(): bool
    {
        return $this->differe;
    }

    public function setDiffere(bool $differe): self
    {
        $this->differe = $differe;

        return $this;
    }

    public function getDateHeure(): \DateTimeImmutable
    {
        return $this->dateHeure;
    }

    public function setDateHeure(\DateTimeImmutable $dateHeure): self
    {
        $this->dateHeure = $dateHeure;

        return $this;
    }
}
