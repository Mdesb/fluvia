<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use App\Boutique\Enum\StatutRetraitPhysique;
use App\Vente\Entity\BilletSupport;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Satellite `OneToOne` de `App\Vente\Entity\BilletSupport` : « BilletQR » du cahier (RG-M3-04). Porte
 * le QR dynamique, le repli wallet (RG-M3-14) et le statut de retrait physique (RG-M3-18).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_billet_qr_meta')]
#[ORM\UniqueConstraint(name: 'uniq_billet_qr_meta_support', columns: ['billet_support_id'])]
class BilletQrMeta
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['billet:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: BilletSupport::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['billet:read'])]
    private ?BilletSupport $billetSupport = null;

    #[ORM\Column(length: 255)]
    #[Groups(['billet:read'])]
    private string $qrDynamique = '';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['billet:read'])]
    private bool $passWalletDisponible = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['billet:read'])]
    private ?string $passWalletUrl = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['billet:read'])]
    private bool $repliQr = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['billet:read'])]
    private ?\DateTimeImmutable $validiteDebut = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['billet:read'])]
    private ?\DateTimeImmutable $validiteFin = null;

    #[ORM\Column(length: 14, enumType: StatutRetraitPhysique::class, nullable: true)]
    #[Groups(['billet:read'])]
    private ?StatutRetraitPhysique $statutRetraitPhysique = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBilletSupport(): ?BilletSupport
    {
        return $this->billetSupport;
    }

    public function setBilletSupport(?BilletSupport $billetSupport): self
    {
        $this->billetSupport = $billetSupport;

        return $this;
    }

    public function getQrDynamique(): string
    {
        return $this->qrDynamique;
    }

    public function setQrDynamique(string $qrDynamique): self
    {
        $this->qrDynamique = $qrDynamique;

        return $this;
    }

    public function isPassWalletDisponible(): bool
    {
        return $this->passWalletDisponible;
    }

    public function setPassWalletDisponible(bool $passWalletDisponible): self
    {
        $this->passWalletDisponible = $passWalletDisponible;

        return $this;
    }

    public function getPassWalletUrl(): ?string
    {
        return $this->passWalletUrl;
    }

    public function setPassWalletUrl(?string $passWalletUrl): self
    {
        $this->passWalletUrl = $passWalletUrl;

        return $this;
    }

    public function isRepliQr(): bool
    {
        return $this->repliQr;
    }

    public function setRepliQr(bool $repliQr): self
    {
        $this->repliQr = $repliQr;

        return $this;
    }

    public function getValiditeDebut(): ?\DateTimeImmutable
    {
        return $this->validiteDebut;
    }

    public function setValiditeDebut(?\DateTimeImmutable $validiteDebut): self
    {
        $this->validiteDebut = $validiteDebut;

        return $this;
    }

    public function getValiditeFin(): ?\DateTimeImmutable
    {
        return $this->validiteFin;
    }

    public function setValiditeFin(?\DateTimeImmutable $validiteFin): self
    {
        $this->validiteFin = $validiteFin;

        return $this;
    }

    public function getStatutRetraitPhysique(): ?StatutRetraitPhysique
    {
        return $this->statutRetraitPhysique;
    }

    public function setStatutRetraitPhysique(?StatutRetraitPhysique $statutRetraitPhysique): self
    {
        $this->statutRetraitPhysique = $statutRetraitPhysique;

        return $this;
    }
}
