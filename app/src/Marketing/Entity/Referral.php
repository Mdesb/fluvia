<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Marketing\State\ReferralListProvider;
use App\Marketing\State\ReferralRewardProcessor;
use App\Marketing\State\ReferralDeclarationProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UN PARRAINAGE — le lien, et rien de son état.
 *
 * L'état (« en attente », « éligible », « récompensé ») ne se stocke pas : il se déduit des ventes
 * du filleul à chaque lecture. Un statut stocké resterait « en attente » le jour où le filleul
 * achète, jusqu'à ce qu'un traitement pense à le mettre à jour — et personne ne verrait qu'il ne
 * l'a pas fait.
 *
 * > **Un état qui se calcule ne se stocke pas.**
 *
 * Ce qui se stocke ici, c'est ce qu'aucun calcul ne retrouverait : **le lien lui-même** (qui a
 * parrainé qui, quand) et **le versement** (quand, combien).
 *
 * ── UN FILLEUL NE SE PARRAINE QU'UNE FOIS, ET C'EST LA BASE QUI LE TIENT ────────────────────────
 *
 * `uniq_marketing_referral_filleul` porte sur l'établissement et le filleul. Ce n'est pas un
 * contrôle applicatif, et c'est délibéré : deux requêtes simultanées passeraient toutes deux un
 * `findOneBy` avant que l'une n'écrive. Une contrainte d'unicité ne se laisse pas doubler.
 *
 * ── LA RÉCOMPENSE EST FIGÉE AU VERSEMENT ────────────────────────────────────────────────────────
 *
 * `rewardedPoints` recopie ce que valait le programme le jour du versement. C'est la seule
 * duplication de tout le module, et elle est volontaire : sans elle, doubler la récompense demain
 * changerait rétroactivement ce qu'on a versé hier, dans un état qu'on ne saurait plus reconstituer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_referral')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_referral_filleul', columns: ['establishment_id', 'referee_ref'])]
#[ORM\Index(name: 'idx_marketing_referral_parrain', columns: ['establishment_id', 'sponsor_ref'])]
#[ApiResource(
    shortName: 'Referral',
    operations: [
        // LA LISTE, avec l'état calculé de chacun. Provider dédié : l'état n'est pas en base.
        new Get(
            uriTemplate: '/marketing/parrainages',
            security: "is_granted('PERM', 'fidelite.lire')",
            provider: ReferralListProvider::class,
        ),

        // DÉCLARER un parrainage : un code de parrain, un filleul.
        new Post(
            uriTemplate: '/marketing/parrainages',
            security: "is_granted('PERM', 'fidelite.gerer')",
            denormalizationContext: ['groups' => ['referral:write']],
            processor: ReferralDeclarationProcessor::class,
        ),

        // VERSER la récompense. Refusé si le filleul n'a rien acheté, refusé si déjà versée.
        new Post(
            uriTemplate: '/marketing/parrainages/{id}/recompenser',
            security: "is_granted('PERM', 'fidelite.gerer')",
            processor: ReferralRewardProcessor::class,
            deserialize: false,
        ),
    ],
    normalizationContext: ['groups' => ['referral:read']],
)]
class Referral
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['referral:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['referral:read'])]
    private ?Etablissement $establishment = null;

    /** Référence libre vers `App\Crm\Entity\Client` (D2) — le parrain. */
    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['referral:read'])]
    private ?Uuid $sponsorRef = null;

    /** Référence libre vers `App\Crm\Entity\Client` (D2) — le filleul. */
    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['referral:read', 'referral:write'])]
    #[Assert\NotNull(message: 'Un parrainage désigne quelqu’un.')]
    private ?Uuid $refereeRef = null;

    /**
     * Le code utilisé. Recopié pour la trace : le parrain peut en changer, le parrainage garde
     * celui qui l'a produit.
     */
    #[ORM\Column(length: 16)]
    #[Groups(['referral:read', 'referral:write'])]
    #[Assert\NotBlank(message: 'Sans code, on ne sait pas qui parraine.')]
    private string $code = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['referral:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['referral:read'])]
    private ?\DateTimeImmutable $rewardedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['referral:read'])]
    private int $rewardedPoints = 0;

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

    public function getSponsorRef(): ?Uuid
    {
        return $this->sponsorRef;
    }

    public function setSponsorRef(?Uuid $sponsorRef): self
    {
        $this->sponsorRef = $sponsorRef;

        return $this;
    }

    public function getRefereeRef(): ?Uuid
    {
        return $this->refereeRef;
    }

    public function setRefereeRef(?Uuid $refereeRef): self
    {
        $this->refereeRef = $refereeRef;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRewardedAt(): ?\DateTimeImmutable
    {
        return $this->rewardedAt;
    }

    public function getRewardedPoints(): int
    {
        return $this->rewardedPoints;
    }

    /** Le versement, en un seul geste : la date et le montant ne se posent jamais séparément. */
    public function marquerRecompense(int $points, \DateTimeImmutable $quand): self
    {
        $this->rewardedPoints = $points;
        $this->rewardedAt = $quand;

        return $this;
    }
}
