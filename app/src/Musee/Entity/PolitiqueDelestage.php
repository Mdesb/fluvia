<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Musee\Enum\ModeDelestage;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Politique de délestage d'une salle saturée (⚠ HYPOTHÈSE, mécanique non détaillée par les sources,
 * §4.2, CA-2). **Information de supervision et consigne** appliquée par l'agent mobile via le tableau
 * de bord (`SalleEtatLive`) — ne modifie **pas** `ValidationPassageHandler` (L3, inchangé).
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_politique_delestage')]
#[ORM\UniqueConstraint(name: 'uniq_politique_delestage_sous_quota', columns: ['sous_quota_salle_id'])]
#[ApiResource(
    shortName: 'MuseePolitiqueDelestage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.configurer')"),
        new Patch(security: "is_granted('PERM', 'musee.configurer')"),
    ],
    normalizationContext: ['groups' => ['delestage:read']],
    denormalizationContext: ['groups' => ['delestage:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['sousQuotaSalle' => 'exact'])]
class PolitiqueDelestage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['delestage:read', 'salle_live:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: SousQuotaSalle::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['delestage:read', 'delestage:write', 'salle_live:read'])]
    private ?SousQuotaSalle $sousQuotaSalle = null;

    #[ORM\Column(length: 24, enumType: ModeDelestage::class, options: ['default' => 'alerte_seule'])]
    #[Groups(['delestage:read', 'delestage:write', 'salle_live:read'])]
    private ModeDelestage $mode = ModeDelestage::AlerteSeule;

    #[ORM\ManyToOne(targetEntity: Salle::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['delestage:read', 'delestage:write'])]
    private ?Salle $salleRedirection = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['delestage:read', 'delestage:write', 'salle_live:read'])]
    private ?string $messageAgent = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['delestage:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSousQuotaSalle(): ?SousQuotaSalle
    {
        return $this->sousQuotaSalle;
    }

    public function setSousQuotaSalle(?SousQuotaSalle $sousQuotaSalle): self
    {
        $this->sousQuotaSalle = $sousQuotaSalle;
        if ($sousQuotaSalle !== null) {
            $this->etablissement = $sousQuotaSalle->getEtablissement();
        }

        return $this;
    }

    public function getMode(): ModeDelestage
    {
        return $this->mode;
    }

    public function setMode(ModeDelestage $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getSalleRedirection(): ?Salle
    {
        return $this->salleRedirection;
    }

    public function setSalleRedirection(?Salle $salleRedirection): self
    {
        $this->salleRedirection = $salleRedirection;

        return $this;
    }

    public function getMessageAgent(): ?string
    {
        return $this->messageAgent;
    }

    public function setMessageAgent(?string $messageAgent): self
    {
        $this->messageAgent = $messageAgent;

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
}
