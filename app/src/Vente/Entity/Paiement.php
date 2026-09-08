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
 *
 * ── LA CLÉ D'IDEMPOTENCE, ET POURQUOI ELLE EST PORTÉE PAR (VENTE, CLÉ) ─────────────────────────
 *
 * `Vente` porte déjà la sienne : ouvrir deux fois le même panier ne crée qu'une vente. Le RÈGLEMENT
 * n'en avait aucune, et c'est lui qui déplace de l'argent — le débit du porte-monnaie et l'ordre au
 * TPE partent tous deux avant qu'une seule ligne soit écrite. Une réponse perdue et un client qui
 * rejoue encaissaient donc deux fois.
 *
 * ⚠ **La portée est le couple, pas la clé seule.** Une contrainte globale et une recherche menée
 * dans la collection de la vente ne diraient pas la même chose : une clé déjà employée sur une AUTRE
 * vente passerait la recherche et se ferait refuser au `flush()`, en 500, après que la carte a été
 * débitée. La contrainte et la recherche portent donc exactement le même couple.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vente_paiement')]
#[ORM\UniqueConstraint(name: 'uniq_paiement_vente_cle', columns: ['vente_id', 'cle_idempotence'])]
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

    /**
     * Nullable : les règlements antérieurs n'en portent aucune, et un `NOT NULL` aurait exigé d'en
     * inventer une pour eux — donc d'écrire dans des lignes que `InalterabiliteListener` protège.
     * Absente = ce règlement n'a jamais été rejouable, ce qui est la vérité pour tout l'historique.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read', 'paiement:read'])]
    private ?Uuid $cleIdempotence = null;

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

    public function getCleIdempotence(): ?Uuid
    {
        return $this->cleIdempotence;
    }

    public function setCleIdempotence(?Uuid $cle): self
    {
        $this->cleIdempotence = $cle;

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
