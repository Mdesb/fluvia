<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use App\Reporting\Entity\Trait\RattachementNiveauInterface;
use App\Reporting\Entity\Trait\RattachementNiveauTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Destinataire d'un `RapportPlanifie` (§1.8 plan-reporting.md, RG-M7-07) : porte son PROPRE
 * périmètre, distinct de celui du rapport — chaque destinataire ne reçoit que les données de son
 * périmètre, y compris quand le rapport est mutualisé entre plusieurs niveaux (CA-8). Entité
 * enfant, non exposée en `#[ApiResource]` propre (même patron que `LigneVente` sur `Vente`) :
 * accessible en écriture via la dénormalisation embarquée de `RapportPlanifie` (groupe `rapport:write`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_destinataire_rapport')]
class DestinataireRapport implements RattachementNiveauInterface
{
    use RattachementNiveauTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['rapport:read', 'rapport:write'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RapportPlanifie::class, inversedBy: 'destinataires')]
    #[ORM\JoinColumn(nullable: false)]
    private ?RapportPlanifie $rapportPlanifie = null;

    /** Pas nécessairement un `Utilisateur` du système (RG-M7-07 : « liste e-mail »). */
    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['rapport:read', 'rapport:write'])]
    private string $email = '';

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRapportPlanifie(): ?RapportPlanifie
    {
        return $this->rapportPlanifie;
    }

    public function setRapportPlanifie(?RapportPlanifie $rapportPlanifie): self
    {
        $this->rapportPlanifie = $rapportPlanifie;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }
}
