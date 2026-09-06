<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use App\Boutique\Enum\StatutTunnel;
use App\Organisation\Entity\Etablissement;
use App\Vente\Entity\Vente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Satellite `OneToOne` de `App\Vente\Entity\Vente` (§0 décision n°1 du plan) : « CommandeEnLigne » du
 * cahier = la `Vente` M2 (canal `en_ligne`), pas une nouvelle table. Porte les seuls champs propres à
 * la boutique (étape du tunnel, vitrine, panier d'origine, origine OTA).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_suivi_commande')]
#[ORM\UniqueConstraint(name: 'uniq_suivi_commande_vente', columns: ['vente_id'])]
class SuiviCommandeEnLigne
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['panier:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['panier:read'])]
    private ?Vente $vente = null;

    #[ORM\ManyToOne(targetEntity: Vitrine::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vitrine $vitrine = null;

    #[ORM\ManyToOne(targetEntity: PanierEnLigne::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?PanierEnLigne $panierOrigine = null;

    #[ORM\ManyToOne(targetEntity: CompteClient::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?CompteClient $compteClient = null;

    #[ORM\Column(length: 10, enumType: StatutTunnel::class, options: ['default' => 'panier'])]
    #[Groups(['panier:read'])]
    private StatutTunnel $statutTunnel = StatutTunnel::Panier;

    #[ORM\Column(options: ['default' => false])]
    private bool $origineOta = false;

    #[ORM\ManyToOne(targetEntity: PartenaireOTA::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PartenaireOTA $partenaireOta = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    /**
     * CE QUI A ÉTÉ INITIÉ CHEZ LE PRESTATAIRE — la référence et le montant, mémorisés au moment où on
     * les lui a confiés. Le retour de paiement se confronte à eux : une référence inconnue ou un
     * montant différent ne confirment rien (audit 06/09, constat 1). `null` tant qu'aucune initiation.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $paymentReference = null;

    #[ORM\Column(nullable: true)]
    private ?int $paymentAmountCents = null;

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

    public function getVitrine(): ?Vitrine
    {
        return $this->vitrine;
    }

    public function setVitrine(?Vitrine $vitrine): self
    {
        $this->vitrine = $vitrine;

        return $this;
    }

    public function getPanierOrigine(): ?PanierEnLigne
    {
        return $this->panierOrigine;
    }

    public function setPanierOrigine(?PanierEnLigne $panierOrigine): self
    {
        $this->panierOrigine = $panierOrigine;

        return $this;
    }

    public function getCompteClient(): ?CompteClient
    {
        return $this->compteClient;
    }

    public function setCompteClient(?CompteClient $compteClient): self
    {
        $this->compteClient = $compteClient;

        return $this;
    }

    public function getStatutTunnel(): StatutTunnel
    {
        return $this->statutTunnel;
    }

    public function setStatutTunnel(StatutTunnel $statutTunnel): self
    {
        $this->statutTunnel = $statutTunnel;

        return $this;
    }

    public function isOrigineOta(): bool
    {
        return $this->origineOta;
    }

    public function setOrigineOta(bool $origineOta): self
    {
        $this->origineOta = $origineOta;

        return $this;
    }

    public function getPartenaireOta(): ?PartenaireOTA
    {
        return $this->partenaireOta;
    }

    public function setPartenaireOta(?PartenaireOTA $partenaireOta): self
    {
        $this->partenaireOta = $partenaireOta;

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

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function getPaymentAmountCents(): ?int
    {
        return $this->paymentAmountCents;
    }

    public function recordPaymentInitiation(string $reference, int $amountCents): self
    {
        $this->paymentReference = $reference;
        $this->paymentAmountCents = $amountCents;

        return $this;
    }
}
