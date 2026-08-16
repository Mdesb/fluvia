<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use App\Boutique\Enum\TypeSessionClient;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Session client final anonyme (invité ou FranceConnect en cours, §4.4 spec-boutique.md). Le jeton
 * est haché (sha256) ; le jeton en clair n'est renvoyé qu'à l'ouverture du panier
 * (`PanierEnLigne::jetonSession`, non persisté).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_session_client')]
#[ORM\UniqueConstraint(name: 'uniq_session_client_token', columns: ['token'])]
class SessionClient
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $token = '';

    #[ORM\Column(length: 24, enumType: TypeSessionClient::class)]
    private TypeSessionClient $type = TypeSessionClient::Invite;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiration;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->expiration = new \DateTimeImmutable('+24 hours');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getType(): TypeSessionClient
    {
        return $this->type;
    }

    public function setType(TypeSessionClient $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): self
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getExpiration(): \DateTimeImmutable
    {
        return $this->expiration;
    }

    public function setExpiration(\DateTimeImmutable $expiration): self
    {
        $this->expiration = $expiration;

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
