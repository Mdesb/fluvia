<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\Enum\StatutEnvoi;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Facture B2G (Chorus Pro, §4.7 spec). ⚠ HYPOTHÈSE — format exact non détaillé dans les sources.
 * Port `ChorusProInterface` (stub) non branché sur un flux réel dans ce lot.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_facture_b2g')]
#[ApiResource(
    shortName: 'FactureB2G',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
    ],
    normalizationContext: ['groups' => ['b2g:read']],
    denormalizationContext: ['groups' => ['b2g:write']],
)]
class FactureB2G
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['b2g:read'])]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['b2g:read', 'b2g:write'])]
    private Uuid $clientRef;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['b2g:read', 'b2g:write'])]
    private ?string $numeroEngagement = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['b2g:read', 'b2g:write'])]
    private ?string $serviceExecutant = null;

    #[ORM\Column(length: 10, enumType: StatutEnvoi::class, options: ['default' => 'prepare'])]
    #[Groups(['b2g:read'])]
    private StatutEnvoi $statutEnvoi = StatutEnvoi::Prepare;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->clientRef = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClientRef(): Uuid
    {
        return $this->clientRef;
    }

    public function setClientRef(Uuid $clientRef): self
    {
        $this->clientRef = $clientRef;

        return $this;
    }

    public function getNumeroEngagement(): ?string
    {
        return $this->numeroEngagement;
    }

    public function setNumeroEngagement(?string $numeroEngagement): self
    {
        $this->numeroEngagement = $numeroEngagement;

        return $this;
    }

    public function getServiceExecutant(): ?string
    {
        return $this->serviceExecutant;
    }

    public function setServiceExecutant(?string $serviceExecutant): self
    {
        $this->serviceExecutant = $serviceExecutant;

        return $this;
    }

    public function getStatutEnvoi(): StatutEnvoi
    {
        return $this->statutEnvoi;
    }

    public function setStatutEnvoi(StatutEnvoi $statutEnvoi): self
    {
        $this->statutEnvoi = $statutEnvoi;

        return $this;
    }
}
