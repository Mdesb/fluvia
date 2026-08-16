<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\State\AjouterBeneficiairesPanierProcessor;
use App\Boutique\State\AjouterLignePanierProcessor;
use App\Boutique\State\EnregistrerConsentementPanierProcessor;
use App\Boutique\State\IdentifierPanierProcessor;
use App\Boutique\State\OuvrirPanierProcessor;
use App\Boutique\State\PayerPanierProcessor;
use App\Boutique\State\RetirerLignePanierProcessor;
use App\Boutique\State\RetourPaiementProcessor;
use App\Boutique\State\ViderPanierEnLigneProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Panier en ligne (US-L8-03, RG-M3-03/16). Réservation temporaire du stock/créneau tant que non payé
 * dans le délai configuré par la vitrine ; libéré automatiquement à expiration (aucune `Reservation`
 * créée, §4.3 spec). Accessible en `PUBLIC_ACCESS` pour un invité, protégé par un jeton de panier
 * applicatif (`App\Boutique\Security\PanierProprietaireGuard`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_panier')]
#[ApiResource(
    shortName: 'PanierEnLigne',
    operations: [
        new Post(
            uriTemplate: '/boutique/paniers',
            read: false,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: OuvrirPanierProcessor::class,
            normalizationContext: ['groups' => ['panier:read', 'panier:creation']],
        ),
        new Get(
            uriTemplate: '/boutique/paniers/{id}',
            security: "is_granted('PUBLIC_ACCESS')",
        ),
        new Post(
            uriTemplate: '/boutique/paniers/{id}/lignes',
            read: true,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: AjouterLignePanierProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/paniers/{id}/lignes/{ligneId}/retirer',
            read: true,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: RetirerLignePanierProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/paniers/{id}/vider',
            read: true,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: ViderPanierEnLigneProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/paniers/{id}/identifier',
            read: true,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: IdentifierPanierProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/paniers/{id}/beneficiaires',
            read: true,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: AjouterBeneficiairesPanierProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/paniers/{id}/consentement',
            read: true,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: EnregistrerConsentementPanierProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/paniers/{id}/payer',
            read: true,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: PayerPanierProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/paniers/{id}/retour-paiement',
            read: true,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: RetourPaiementProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['panier:read']],
)]
class PanierEnLigne
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['panier:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vitrine::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['panier:read'])]
    private ?Vitrine $vitrine = null;

    #[ORM\ManyToOne(targetEntity: CompteClient::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['panier:read'])]
    private ?CompteClient $compteClient = null;

    #[ORM\ManyToOne(targetEntity: SessionClient::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?SessionClient $sessionClient = null;

    #[ORM\Column(length: 24, enumType: StatutPanier::class, options: ['default' => 'ouvert'])]
    #[Groups(['panier:read'])]
    private StatutPanier $statut = StatutPanier::Ouvert;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['panier:read'])]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['panier:read'])]
    private \DateTimeImmutable $dateExpiration;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['panier:read'])]
    private ?string $contactConnu = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $relanceEnvoyee = false;

    /** RGPD (RG-M3-07) : horodatage du consentement bloquant avant paiement. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['panier:read'])]
    private ?\DateTimeImmutable $consentementRgpdHorodatage = null;

    /** Réf. logique Client (M4) résolu au plus tard à l'étape consentement (§0 décision n°4 du plan). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $clientResolu = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    /** @var Collection<int, LignePanierEnLigne> */
    #[ORM\OneToMany(targetEntity: LignePanierEnLigne::class, mappedBy: 'panier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['panier:read'])]
    private Collection $lignes;

    /** Jeton de session en clair — présent uniquement dans la réponse de création (non persisté). */
    #[Groups(['panier:creation'])]
    private ?string $jetonSession = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateExpiration = new \DateTimeImmutable('+15 minutes');
        $this->lignes = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getCompteClient(): ?CompteClient
    {
        return $this->compteClient;
    }

    public function setCompteClient(?CompteClient $compteClient): self
    {
        $this->compteClient = $compteClient;

        return $this;
    }

    public function getSessionClient(): ?SessionClient
    {
        return $this->sessionClient;
    }

    public function setSessionClient(?SessionClient $sessionClient): self
    {
        $this->sessionClient = $sessionClient;

        return $this;
    }

    public function getStatut(): StatutPanier
    {
        return $this->statut;
    }

    public function setStatut(StatutPanier $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateExpiration(): \DateTimeImmutable
    {
        return $this->dateExpiration;
    }

    public function setDateExpiration(\DateTimeImmutable $dateExpiration): self
    {
        $this->dateExpiration = $dateExpiration;

        return $this;
    }

    public function getContactConnu(): ?string
    {
        return $this->contactConnu;
    }

    public function setContactConnu(?string $contactConnu): self
    {
        $this->contactConnu = $contactConnu;

        return $this;
    }

    public function isRelanceEnvoyee(): bool
    {
        return $this->relanceEnvoyee;
    }

    public function setRelanceEnvoyee(bool $relanceEnvoyee): self
    {
        $this->relanceEnvoyee = $relanceEnvoyee;

        return $this;
    }

    public function getConsentementRgpdHorodatage(): ?\DateTimeImmutable
    {
        return $this->consentementRgpdHorodatage;
    }

    public function setConsentementRgpdHorodatage(?\DateTimeImmutable $consentementRgpdHorodatage): self
    {
        $this->consentementRgpdHorodatage = $consentementRgpdHorodatage;

        return $this;
    }

    public function getClientResolu(): ?Uuid
    {
        return $this->clientResolu;
    }

    public function setClientResolu(?Uuid $clientResolu): self
    {
        $this->clientResolu = $clientResolu;

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

    /** @return Collection<int, LignePanierEnLigne> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LignePanierEnLigne $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setPanier($this);
        }

        return $this;
    }

    public function removeLigne(LignePanierEnLigne $ligne): self
    {
        $this->lignes->removeElement($ligne);

        return $this;
    }

    public function getJetonSession(): ?string
    {
        return $this->jetonSession;
    }

    public function setJetonSession(?string $jetonSession): self
    {
        $this->jetonSession = $jetonSession;

        return $this;
    }

    public function estExpire(\DateTimeImmutable $reference = new \DateTimeImmutable()): bool
    {
        return $this->dateExpiration <= $reference;
    }
}
