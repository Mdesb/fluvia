<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Marketing\State\MarketingEstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UN PALIER — un seuil, un nom, rien d'autre.
 *
 * Le palier ne donne aucun avantage automatique : il **nomme** un niveau de fidélité, et
 * l'exploitant décide ce qu'il en fait. Faire dépendre une remise du palier reviendrait à écrire un
 * moteur tarifaire dans le module Campagnes, alors qu'il en existe un dans le module Offre (D2).
 *
 * ── LE PALIER SE CALCULE SUR DOUZE MOIS, LE SOLDE SUR TOUTE LA VIE ──────────────────────────────
 *
 * Ce sont deux questions différentes, et les confondre casse le programme dans un sens ou dans
 * l'autre :
 *
 *   - le **solde** dit ce qu'il reste à dépenser — il diminue quand on dépense ;
 *   - le **palier** dit à quel point on est un bon client — il ne doit PAS diminuer quand on
 *     dépense ses points, sinon utiliser sa fidélité fait perdre son statut, ce qui apprend au
 *     client à ne jamais s'en servir.
 *
 * Le palier se lit donc sur les points **gagnés sur douze mois glissants**, indépendamment des
 * dépenses.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_loyalty_tier')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_loyalty_tier_libelle', columns: ['establishment_id', 'label'])]
#[ORM\UniqueConstraint(name: 'uniq_marketing_loyalty_tier_seuil', columns: ['establishment_id', 'threshold'])]
#[ApiResource(
    shortName: 'LoyaltyTier',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'fidelite.lire')"),
        new Post(
            security: "is_granted('PERM', 'fidelite.parametrer')",
            denormalizationContext: ['groups' => ['loyalty_tier:write']],
            processor: MarketingEstablishmentStampProcessor::class,
        ),
        new Delete(security: "is_granted('PERM', 'fidelite.parametrer')"),
    ],
    normalizationContext: ['groups' => ['loyalty_tier:read']],
)]
class LoyaltyTier
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['loyalty_tier:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['loyalty_tier:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 60)]
    #[Groups(['loyalty_tier:read', 'loyalty_tier:write'])]
    #[Assert\NotBlank(message: 'Un palier sans nom ne se dit pas au client.')]
    #[Assert\Length(max: 60)]
    private string $label = '';

    /** Points gagnés sur douze mois glissants à partir desquels le palier est atteint. */
    #[ORM\Column]
    #[Groups(['loyalty_tier:read', 'loyalty_tier:write'])]
    #[Assert\Positive(message: 'Un palier à zéro point serait atteint par tout le monde, y compris ceux qui ne sont jamais venus.')]
    private int $threshold = 100;

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function setThreshold(int $threshold): self
    {
        $this->threshold = $threshold;

        return $this;
    }
}
