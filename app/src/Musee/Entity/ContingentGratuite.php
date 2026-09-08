<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use App\Musee\Enum\PerimetreContingent;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contingent de gratuités dédié (décision actée « Gratuités scolaires », §4.5, RG-MUS-03) : une
 * gratuité décrémente **simultanément** le quota de jauge du créneau (RG-MUS-01) et ce contingent,
 * pour ne pas assécher la vente grand public.
 *
 * ⚠ DÉPRÉCIÉ — remplacé par les contingents transverses `App\Group\Entity\GroupGratuiteContingent`
 * (absorption musée, Phase B). Plus exposé en API ; table conservée le temps de valider. À retirer en B4.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_contingent_gratuite')]
class ContingentGratuite
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['contingent:read', 'gratuite:read'])]
    private Uuid $id;

    #[ORM\Column(length: 11, enumType: PerimetreContingent::class)]
    #[Assert\NotNull]
    #[Groups(['contingent:read', 'contingent:write'])]
    private ?PerimetreContingent $perimetre = null;

    #[ORM\ManyToOne(targetEntity: Exposition::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['contingent:read', 'contingent:write'])]
    private ?Exposition $exposition = null;

    #[ORM\ManyToOne(targetEntity: Creneau::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['contingent:read', 'contingent:write'])]
    private ?Creneau $creneau = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    #[Groups(['contingent:read', 'contingent:write'])]
    private int $quotaGratuitesDedie = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['contingent:read'])]
    private int $quotaConsomme = 0;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['contingent:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPerimetre(): ?PerimetreContingent
    {
        return $this->perimetre;
    }

    public function setPerimetre(?PerimetreContingent $perimetre): self
    {
        $this->perimetre = $perimetre;

        return $this;
    }

    public function getExposition(): ?Exposition
    {
        return $this->exposition;
    }

    public function setExposition(?Exposition $exposition): self
    {
        $this->exposition = $exposition;

        return $this;
    }

    public function getCreneau(): ?Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(?Creneau $creneau): self
    {
        $this->creneau = $creneau;

        return $this;
    }

    public function getQuotaGratuitesDedie(): int
    {
        return $this->quotaGratuitesDedie;
    }

    public function setQuotaGratuitesDedie(int $quotaGratuitesDedie): self
    {
        $this->quotaGratuitesDedie = $quotaGratuitesDedie;

        return $this;
    }

    public function getQuotaConsomme(): int
    {
        return $this->quotaConsomme;
    }

    public function setQuotaConsomme(int $quotaConsomme): self
    {
        $this->quotaConsomme = $quotaConsomme;

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

    public function placesRestantes(): int
    {
        return max(0, $this->quotaGratuitesDedie - $this->quotaConsomme);
    }
}
