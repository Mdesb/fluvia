<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Marketing\State\MarketingEstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * LE PROGRAMME DE PARRAINAGE — deux nombres, et une décision qui tient tout.
 *
 * `minimumPurchase` est la décision : **la récompense se déclenche sur un achat vérifiable, jamais
 * sur une inscription.** Un parrainage payé à l'inscription se transforme en usine à faux comptes
 * en quelques jours — c'est la façon la plus courante de ruiner un programme de parrainage, et elle
 * ne se répare pas après coup : les points sont déjà versés.
 *
 * > **On récompense ce qui a été encaissé, jamais ce qui a été déclaré.**
 *
 * ── POURQUOI LE MONTANT N'EST PAS VERSIONNÉ DANS LE TEMPS ───────────────────────────────────────
 *
 * Contrairement au barème de fidélité, ce programme n'a pas besoin de dates : la récompense est
 * **figée sur le parrainage** au moment où elle est versée (`Referral::rewardedPoints`). Changer le
 * programme demain ne touche donc pas ce qui a déjà été payé. Le passé est protégé par la trace,
 * pas par le calendrier — et c'est plus simple à tenir.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_referral_program')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_referral_program', columns: ['establishment_id'])]
#[ApiResource(
    shortName: 'ReferralProgram',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'fidelite.lire')"),
        new Post(
            security: "is_granted('PERM', 'fidelite.parametrer')",
            denormalizationContext: ['groups' => ['referral_program:write']],
            processor: MarketingEstablishmentStampProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'fidelite.parametrer')",
            denormalizationContext: ['groups' => ['referral_program:write']],
        ),
    ],
    normalizationContext: ['groups' => ['referral_program:read']],
)]
class ReferralProgram
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['referral_program:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['referral_program:read'])]
    private ?Etablissement $establishment = null;

    /** Points versés au PARRAIN quand son filleul a réellement acheté. */
    #[ORM\Column(options: ['default' => 100])]
    #[Groups(['referral_program:read', 'referral_program:write'])]
    #[Assert\Positive(message: 'Une récompense à zéro point n’est pas un programme de parrainage.')]
    private int $rewardPoints = 100;

    /**
     * Le montant d'achat qui déclenche la récompense.
     *
     * Zéro est autorisé — l'exploitant peut vouloir récompenser le premier passage quel qu'il soit —
     * mais il faut **un achat validé**, pas seulement une inscription. C'est le service qui le tient.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '10.00'])]
    #[Groups(['referral_program:read', 'referral_program:write'])]
    #[Assert\PositiveOrZero]
    private string $minimumPurchase = '10.00';

    /** Un programme se suspend ; il ne s'efface pas, sinon les parrainages en cours perdent leur règle. */
    #[ORM\Column(options: ['default' => true])]
    #[Groups(['referral_program:read', 'referral_program:write'])]
    private bool $enabled = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    public function getRewardPoints(): int
    {
        return $this->rewardPoints;
    }

    public function setRewardPoints(int $rewardPoints): self
    {
        $this->rewardPoints = $rewardPoints;

        return $this;
    }

    public function getMinimumPurchase(): string
    {
        return $this->minimumPurchase;
    }

    public function setMinimumPurchase(string $minimumPurchase): self
    {
        $this->minimumPurchase = $minimumPurchase;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}
