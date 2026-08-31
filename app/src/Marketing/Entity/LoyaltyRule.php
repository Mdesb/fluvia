<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
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
 * LE BARÈME — daté, donc jamais rétroactif.
 *
 * Une règle vaut **à partir** d'une date et jusqu'à la suivante. C'est la seule chose qui empêche le
 * désastre suivant : l'exploitant passe de 1 à 2 points par euro, et le lendemain tous les soldes
 * de tous les clients ont doublé — y compris ceux gagnés il y a trois ans, sous un barème que
 * personne n'avait promis.
 *
 * Les points se recalculant depuis les ventes (voir `LoyaltyMovement`), une règle non datée
 * réécrirait le passé à chaque modification. Datée, elle ne touche que l'avenir.
 *
 * > **Un barème qui change ne doit pas changer ce qui est déjà acquis.**
 *
 * ── POURQUOI ON N'EFFACE PAS UNE RÈGLE ──────────────────────────────────────────────────────────
 *
 * Pas de `Delete`, pas de `Patch` : corriger une règle passée changerait des soldes déjà annoncés
 * aux clients. Se tromper de barème se répare en en posant un nouveau, pas en effaçant l'ancien —
 * exactement comme on ne rature pas une écriture comptable.
 *
 * ── CE QUE CETTE VERSION NE FAIT PAS ────────────────────────────────────────────────────────────
 *
 * Pas de barème par produit ni par catégorie : « double points sur les abonnements » demanderait de
 * lire le détail des lignes de vente, donc un port vers le module Vente (D2). C'est écrit ici pour
 * que la prochaine version ajoute un port et non un raccourci.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_loyalty_rule')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_loyalty_rule_debut', columns: ['establishment_id', 'valid_from'])]
#[ApiResource(
    shortName: 'LoyaltyRule',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'fidelite.lire')"),
        new Post(
            security: "is_granted('PERM', 'fidelite.parametrer')",
            denormalizationContext: ['groups' => ['loyalty_rule:write']],
            processor: MarketingEstablishmentStampProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['loyalty_rule:read']],
)]
class LoyaltyRule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['loyalty_rule:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['loyalty_rule:read'])]
    private ?Etablissement $establishment = null;

    /**
     * Points accordés par euro dépensé, arrondi à l'euro INFÉRIEUR.
     *
     * L'arrondi va toujours vers le bas : accorder un point qu'on n'a pas gagné est une dette
     * qu'on découvre au moment de l'échanger.
     */
    #[ORM\Column(options: ['default' => 1])]
    #[Groups(['loyalty_rule:read', 'loyalty_rule:write'])]
    #[Assert\Range(
        min: 0,
        max: 1000,
        notInRangeMessage: 'Un barème se situe entre 0 et 1000 points par euro : au-delà, c’est une erreur de saisie.',
    )]
    private int $pointsPerEuro = 1;

    /** La date d'entrée en vigueur. Les ventes antérieures gardent le barème d'avant. */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['loyalty_rule:read', 'loyalty_rule:write'])]
    #[Assert\NotNull(message: 'Une règle sans date d’effet réécrirait le passé.')]
    private \DateTimeImmutable $validFrom;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->validFrom = new \DateTimeImmutable();
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

    public function getPointsPerEuro(): int
    {
        return $this->pointsPerEuro;
    }

    public function setPointsPerEuro(int $pointsPerEuro): self
    {
        $this->pointsPerEuro = $pointsPerEuro;

        return $this;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }
}
