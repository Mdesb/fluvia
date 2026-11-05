<?php

declare(strict_types=1);

namespace App\Kiosk\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Caisse\Entity\PointDeVente;
use App\Kiosk\Enum\KioskDelivery;
use App\Kiosk\State\KioskPairingCodeProcessor;
use App\Kiosk\State\KioskUnpairProcessor;
use App\Kiosk\State\KioskWriteProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une BORNE AUTOMATIQUE DE VENTE (#341) : un point de vente sans caissier.
 *
 * ── CE QU'ELLE EMPRUNTE À SON POINT DE VENTE ─────────────────────────────────────────────────────
 *
 * La chaîne NF525, le terminal de paiement (`PointDeVente.tpe`), les moyens autorisés, l'option « un
 * billet par unité ». Une borne a son PROPRE point de vente, jamais celui d'un guichet : verser ses
 * ventes dans la chaîne du guichet ferait compter au Z du guichet de l'argent qui n'a jamais touché
 * son tiroir (même raisonnement que la vente directe, D44-bis). Ses ventes n'ont pas de session : la
 * borne n'encaisse donc jamais d'espèces ni de chèque (`PaiementHandler`, D44-bis).
 *
 * ── CE QU'ELLE AJOUTE ───────────────────────────────────────────────────────────────────────────
 *
 * - l'APPAIRAGE de l'appareil : un code court, valable un quart d'heure et à usage unique, saisi sur
 *   la borne, qu'elle échange contre un jeton d'appareil. Seules les EMPREINTES sont gardées ; le
 *   jeton n'ouvre que les routes `^/api/kiosk/` (pare-feu dédié), jamais le back-office ;
 * - le CODE DE SORTIE du personnel, haché : il déverrouille l'écran de la borne pour la maintenance ;
 * - l'offre : la liste des produits proposés (vide = tous ceux qui portent le canal « borne »).
 *
 * D41 — `etablissement` vient de la session serveur (`KioskWriteProcessor`), jamais du corps.
 *
 * @sans-suppression: une borne retirée se DÉSACTIVE (`active: false`) et se désappaire ; la supprimer
 * effacerait le lien entre ses ventes et l'appareil qui les a faites, que l'exploitant relit en cas de
 * litige (« j'ai payé et rien n'est sorti »). Ses ventes, elles, restent sur son point de vente.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kiosk')]
#[ORM\UniqueConstraint(name: 'uniq_kiosk_point_of_sale', columns: ['point_of_sale_id'])]
#[ORM\UniqueConstraint(name: 'uniq_kiosk_device_token', columns: ['device_token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_kiosk_pairing_code', columns: ['pairing_code_hash'])]
#[ApiResource(
    shortName: 'Kiosk',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'kiosk.read')"),
        new Get(security: "is_granted('PERM', 'kiosk.read')"),
        new Post(
            security: "is_granted('PERM', 'kiosk.manage')",
            denormalizationContext: ['groups' => ['kiosk:write', 'kiosk:create']],
            processor: KioskWriteProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'kiosk.manage')", processor: KioskWriteProcessor::class),
        new Post(
            uriTemplate: '/kiosks/{id}/pairing-code',
            description: 'Emet un code d appairage (valable 15 minutes, usage unique) a saisir sur la borne. Le code n est rendu qu une fois.',
            input: false,
            security: "is_granted('PERM', 'kiosk.manage')",
            processor: KioskPairingCodeProcessor::class,
        ),
        new Post(
            uriTemplate: '/kiosks/{id}/unpair',
            description: 'Desappaire la borne : son jeton d appareil cesse aussitot d ouvrir quoi que ce soit.',
            input: false,
            security: "is_granted('PERM', 'kiosk.manage')",
            processor: KioskUnpairProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['kiosk:read']],
    denormalizationContext: ['groups' => ['kiosk:write']],
)]
class Kiosk
{
    public const MIN_IDLE_SECONDS = 20;
    public const MAX_IDLE_SECONDS = 600;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['kiosk:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    /** Fixé à la création : changer de point de vente déplacerait les ventes d'une chaîne NF525 à l'autre. */
    #[ORM\ManyToOne(targetEntity: PointDeVente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['kiosk:create'])]
    private ?PointDeVente $pointOfSale = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['kiosk:read', 'kiosk:write'])]
    private string $label = '';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['kiosk:read', 'kiosk:write'])]
    private bool $active = true;

    /** @var list<string>|null UUID de produits proposés ; nul ou vide = tous ceux du canal « borne ». */
    #[ORM\Column(nullable: true)]
    #[Groups(['kiosk:read', 'kiosk:write'])]
    private ?array $productIds = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['kiosk:read', 'kiosk:write'])]
    private bool $rechargeEnabled = true;

    #[ORM\Column(length: 16, enumType: KioskDelivery::class, options: ['default' => 'ticket'])]
    #[Groups(['kiosk:read', 'kiosk:write'])]
    private KioskDelivery $delivery = KioskDelivery::Ticket;

    /** Sans geste pendant ce délai, la borne abandonne le panier et revient à l'accueil. */
    #[ORM\Column(options: ['default' => 60])]
    #[Assert\Range(min: self::MIN_IDLE_SECONDS, max: self::MAX_IDLE_SECONDS)]
    #[Groups(['kiosk:read', 'kiosk:write'])]
    private int $idleSeconds = 60;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exitCodeHash = null;

    /** Échecs consécutifs du code de sortie ; au cinquième, l'écran se ferme cinq minutes. */
    #[ORM\Column(options: ['default' => 0])]
    private int $failedUnlocks = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $unlockBlockedUntil = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceTokenHash = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['kiosk:read'])]
    private ?\DateTimeImmutable $pairedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $pairingCodeHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pairingCodeExpiresAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['kiosk:read'])]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    #[Groups(['kiosk:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Le code de sortie EN CLAIR, reçu à l'écriture, contrôlé (4 à 8 chiffres) et haché par
     * `KioskWriteProcessor` ; jamais lu, jamais stocké.
     */
    #[Groups(['kiosk:write'])]
    private ?string $exitCode = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getPointOfSale(): ?PointDeVente
    {
        return $this->pointOfSale;
    }

    public function setPointOfSale(?PointDeVente $pointOfSale): self
    {
        $this->pointOfSale = $pointOfSale;

        return $this;
    }

    #[Groups(['kiosk:read'])]
    public function getPointOfSaleSummary(): ?array
    {
        if ($this->pointOfSale === null) {
            return null;
        }
        $tpe = $this->pointOfSale->getTpe();

        return [
            'id' => (string) $this->pointOfSale->getId(),
            'label' => $this->pointOfSale->getLibelle(),
            // Tel que réglé sur le point de vente ; nul = le défaut de la plateforme (terminal externe en production).
            'terminalConnector' => \is_array($tpe) && \is_string($tpe['connecteur'] ?? null) ? $tpe['connecteur'] : null,
        ];
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = trim($label);

        return $this;
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

    /** @return list<string> */
    public function getProductIds(): array
    {
        return $this->productIds ?? [];
    }

    /** @param list<string>|null $productIds */
    public function setProductIds(?array $productIds): self
    {
        $this->productIds = $productIds === null || $productIds === [] ? null : array_values(array_unique(array_map('strval', $productIds)));

        return $this;
    }

    public function isRechargeEnabled(): bool
    {
        return $this->rechargeEnabled;
    }

    public function setRechargeEnabled(bool $rechargeEnabled): self
    {
        $this->rechargeEnabled = $rechargeEnabled;

        return $this;
    }

    public function getDelivery(): KioskDelivery
    {
        return $this->delivery;
    }

    public function setDelivery(KioskDelivery $delivery): self
    {
        $this->delivery = $delivery;

        return $this;
    }

    public function getIdleSeconds(): int
    {
        return $this->idleSeconds;
    }

    public function setIdleSeconds(int $idleSeconds): self
    {
        $this->idleSeconds = $idleSeconds;

        return $this;
    }

    public function getExitCode(): ?string
    {
        return $this->exitCode;
    }

    public function setExitCode(?string $exitCode): self
    {
        $this->exitCode = $exitCode;

        return $this;
    }

    public function getExitCodeHash(): ?string
    {
        return $this->exitCodeHash;
    }

    public function setExitCodeHash(?string $exitCodeHash): self
    {
        $this->exitCodeHash = $exitCodeHash;

        return $this;
    }

    #[Groups(['kiosk:read'])]
    public function hasExitCode(): bool
    {
        return $this->exitCodeHash !== null;
    }

    public function getFailedUnlocks(): int
    {
        return $this->failedUnlocks;
    }

    public function setFailedUnlocks(int $failedUnlocks): self
    {
        $this->failedUnlocks = $failedUnlocks;

        return $this;
    }

    public function getUnlockBlockedUntil(): ?\DateTimeImmutable
    {
        return $this->unlockBlockedUntil;
    }

    public function setUnlockBlockedUntil(?\DateTimeImmutable $unlockBlockedUntil): self
    {
        $this->unlockBlockedUntil = $unlockBlockedUntil;

        return $this;
    }

    public function getDeviceTokenHash(): ?string
    {
        return $this->deviceTokenHash;
    }

    #[Groups(['kiosk:read'])]
    public function isPaired(): bool
    {
        return $this->deviceTokenHash !== null;
    }

    public function pair(string $deviceTokenHash, \DateTimeImmutable $at): self
    {
        $this->deviceTokenHash = $deviceTokenHash;
        $this->pairedAt = $at;
        $this->pairingCodeHash = null;
        $this->pairingCodeExpiresAt = null;

        return $this;
    }

    public function unpair(): self
    {
        $this->deviceTokenHash = null;
        $this->pairedAt = null;
        $this->pairingCodeHash = null;
        $this->pairingCodeExpiresAt = null;

        return $this;
    }

    public function getPairedAt(): ?\DateTimeImmutable
    {
        return $this->pairedAt;
    }

    public function getPairingCodeHash(): ?string
    {
        return $this->pairingCodeHash;
    }

    public function getPairingCodeExpiresAt(): ?\DateTimeImmutable
    {
        return $this->pairingCodeExpiresAt;
    }

    public function setPairingCode(?string $hash, ?\DateTimeImmutable $expiresAt): self
    {
        $this->pairingCodeHash = $hash;
        $this->pairingCodeExpiresAt = $expiresAt;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
