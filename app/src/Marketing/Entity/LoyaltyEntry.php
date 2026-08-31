<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Marketing\Enum\LoyaltyMovement;
use App\Marketing\State\LoyaltyProvider;
use App\Marketing\State\LoyaltyMovementProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UNE DÉCISION SUR DES POINTS — et rien d'autre ne se stocke.
 *
 * Cette table ne contient PAS les points gagnés. Ils se recalculent depuis les ventes validées, à
 * chaque lecture, selon le barème en vigueur au jour de chaque vente. Un compteur de points gagnés
 * se désynchroniserait au premier avoir, et un solde faux se lit exactement comme un solde juste.
 *
 * Ne se stocke ici que ce qu'aucun calcul ne saurait retrouver : une dépense, un geste commercial.
 *
 * > **Ce qui se déduit se déduit ; ce qui se décide se stocke.**
 *
 * ── LE MOTIF EST OBLIGATOIRE ────────────────────────────────────────────────────────────────────
 *
 * Un client qui voit son solde bouger demande pourquoi, et « ajustement » n'est pas une réponse.
 * Le motif est saisi au moment du geste, seul instant où quelqu'un le connaît encore.
 *
 * ── L'AUTEUR EST UNE RÉFÉRENCE LIBRE ────────────────────────────────────────────────────────────
 *
 * `Uuid` nu, sans clé étrangère (D2/D58) : la trace d'un geste ne doit pas disparaître avec le
 * compte de l'agent qui l'a fait, ni empêcher sa suppression.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_loyalty_entry')]
#[ORM\Index(name: 'idx_marketing_loyalty_entry_client', columns: ['establishment_id', 'customer_ref'])]
#[ApiResource(
    shortName: 'LoyaltyEntry',
    operations: [
        // LE SOLDE, LE PALIER ET L'HISTORIQUE D'UN CLIENT — tout est calculé, rien n'est stocké.
        //
        // ⚠ `{id}` désigne ici le CLIENT, pas l'écriture. C'est la seule variable d'URI qu'API
        // Platform résout sans mappage vers une propriété, et le provider ne fait aucune lecture
        // implicite : il lit l'identifiant lui-même. Le nommer autrement casserait la route.
        new Get(
            uriTemplate: '/marketing/fidelite/{id}',
            security: "is_granted('PERM', 'fidelite.lire')",
            provider: LoyaltyProvider::class,
        ),

        // LE GESTE : dépenser des points, ou en ajouter. Refusé si le solde ne suit pas.
        new Post(
            uriTemplate: '/marketing/fidelite/mouvements',
            security: "is_granted('PERM', 'fidelite.gerer')",
            denormalizationContext: ['groups' => ['loyalty_entry:write']],
            processor: LoyaltyMovementProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['loyalty_entry:read']],
)]
class LoyaltyEntry
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['loyalty_entry:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['loyalty_entry:read'])]
    private ?Etablissement $establishment = null;

    /** Référence libre vers `App\Crm\Entity\Client` (D2). */
    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['loyalty_entry:read', 'loyalty_entry:write'])]
    #[Assert\NotNull(message: 'Un mouvement de points appartient à quelqu’un.')]
    private ?Uuid $customerRef = null;

    /**
     * Le nombre de points. Négatif pour une dépense.
     *
     * Zéro est refusé : un mouvement qui ne change rien encombre l'historique et fait douter de
     * celui d'à côté.
     */
    #[ORM\Column]
    #[Groups(['loyalty_entry:read', 'loyalty_entry:write'])]
    #[Assert\NotEqualTo(value: 0, message: 'Un mouvement de zéro point n’est pas un mouvement.')]
    private int $points = 0;

    #[ORM\Column(length: 16, enumType: LoyaltyMovement::class)]
    #[Groups(['loyalty_entry:read', 'loyalty_entry:write'])]
    private LoyaltyMovement $movement = LoyaltyMovement::Depense;

    #[ORM\Column(length: 200)]
    #[Groups(['loyalty_entry:read', 'loyalty_entry:write'])]
    #[Assert\NotBlank(message: 'Dites pourquoi : le client demandera, et personne ne s’en souviendra.')]
    #[Assert\Length(min: 3, max: 200)]
    private string $reason = '';

    /** Référence libre vers l'utilisateur auteur du geste — jamais une clé étrangère. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['loyalty_entry:read'])]
    private ?Uuid $authorRef = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['loyalty_entry:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getCustomerRef(): ?Uuid
    {
        return $this->customerRef;
    }

    public function setCustomerRef(?Uuid $customerRef): self
    {
        $this->customerRef = $customerRef;

        return $this;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): self
    {
        $this->points = $points;

        return $this;
    }

    public function getMovement(): LoyaltyMovement
    {
        return $this->movement;
    }

    public function setMovement(LoyaltyMovement $movement): self
    {
        $this->movement = $movement;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getAuthorRef(): ?Uuid
    {
        return $this->authorRef;
    }

    public function setAuthorRef(?Uuid $authorRef): self
    {
        $this->authorRef = $authorRef;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
