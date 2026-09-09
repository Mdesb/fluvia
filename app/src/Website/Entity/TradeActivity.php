<?php

declare(strict_types=1);

namespace App\Website\Entity;

use App\Fonctionnalite\Enum\EstablishmentActivity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une activite declaree par un metier, parmi les neuf de D15.
 *
 * ── POURQUOI UNE TABLE ET PAS UNE COLONNE JSON ─────────────────────────────────────────────────
 *
 * Trois raisons, dans l'ordre d'importance.
 *
 * 1. **`enumType` refuse DANS LES DEUX SENS.** Une colonne typee echoue a l'ecriture *et* a
 *    l'hydratation si la valeur sort des neuf. Une liste JSON n'echoue qu'a l'endroit ou quelqu'un
 *    a pense a valider — c'est-a-dire chez le premier ecrivain, jamais chez le second.
 * 2. **La question du tunnel est l'inverse de celle de la page.** « Quels metiers portent la
 *    location de materiel ? » se lit ici par un `WHERE` indexe ; en JSON, c'est un balayage.
 * 3. **`UNIQUE(trade, activity)` n'a pas d'equivalent JSON.** C'est la STRUCTURE qui interdit le
 *    doublon, pas une verification applicative qu'on peut oublier d'appeler.
 *
 * ⚠ **`ON DELETE CASCADE`, contrairement aux autres liens du module.** Une activite n'a aucune
 * existence hors de son metier : la ligne orpheline ne serait lisible par personne, et elle ferait
 * compter une activite a un metier disparu.
 */
#[ORM\Entity]
#[ORM\Table(name: 'website_trade_activity')]
#[ORM\UniqueConstraint(name: 'uniq_website_trade_activity', columns: ['trade_id', 'activity'])]
#[ORM\Index(name: 'idx_website_trade_activity_type', columns: ['activity'])]
class TradeActivity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Trade::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Trade $trade = null;

    /**
     * ⚠ **UNE VALEUR HORS DES NEUF FAIT LEVER A L'HYDRATATION**, donc sur la page publique et pas
     * devant celui qui a ecrit. C'est assume : c'est le comportement de `CatalogueCapacites`, le
     * seul des neuf points de figement qui ait crie, et donc le seul corrige le jour meme. Une
     * lecture tolerante afficherait un metier ampute d'une activite, indiscernable d'un metier sain.
     */
    #[ORM\Column(length: 32, enumType: EstablishmentActivity::class)]
    private EstablishmentActivity $activity = EstablishmentActivity::Entry;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrade(): ?Trade
    {
        return $this->trade;
    }

    public function setTrade(?Trade $trade): self
    {
        $this->trade = $trade;

        return $this;
    }

    public function getActivity(): EstablishmentActivity
    {
        return $this->activity;
    }

    public function setActivity(EstablishmentActivity $activity): self
    {
        $this->activity = $activity;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }
}
