<?php

declare(strict_types=1);

namespace App\PublicApi\Entity;

use App\PublicApi\Enum\CredentialStatus;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * La cle d'une application tierce.
 *
 * `secretHash` = `hash('sha256', $secret)` — recherche exacte indexee, **pas** bcrypt : meme
 * raisonnement que {@see \App\Acces\Entity\JetonTerminal}, le secret a une forte entropie native
 * (32 octets tires par le serveur, jamais choisis par un humain). Un bcrypt imposerait de balayer
 * la table pour trouver la ligne, ce qui interdit l'index.
 *
 * ⚠ **`prefix` N'EST PAS UN MORCEAU DU SECRET, C'EST UN NOM.** Il faut pouvoir dire « revoque la
 * cle flv_a3f2… » sans jamais reafficher le secret, qui n'est montre qu'une fois a la frappe. Sans
 * lui, une application portant trois cles n'a aucun moyen de designer celle qui a fuite.
 *
 * ⚠ **UNE CLE N'OUVRE AUCUNE DONNEE PAR ELLE-MEME.** Elle identifie l'application ; ce sont les
 * {@see ApiGrant} qui disent quels etablissements ont consenti, et sur quelles portees.
 */
#[ORM\Entity]
#[ORM\Table(name: 'public_api_credential')]
#[ORM\UniqueConstraint(name: 'uniq_public_api_secret_hash', columns: ['secret_hash'])]
#[ORM\Index(columns: ['application_id'], name: 'idx_public_api_credential_application')]
class ApiCredential
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    // RESTRICT : supprimer une application dont des cles vivent ferait disparaitre en silence
    // des acces actifs. La desactivation est le geste normal ; la suppression doit echouer.
    #[ORM\ManyToOne(targetEntity: PartnerApplication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?PartnerApplication $application = null;

    #[ORM\Column(length: 64)]
    private string $secretHash = '';

    /** Les douze premiers caracteres du secret, pour designer la cle sans la reveler. */
    #[ORM\Column(length: 16)]
    private string $prefix = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(length: 12, enumType: CredentialStatus::class, options: ['default' => 'active'])]
    private CredentialStatus $status = CredentialStatus::Active;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    // SET NULL : le depart d'un salarie ne doit pas effacer la trace d'une revocation.
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $revokedBy = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->issuedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getApplication(): ?PartnerApplication
    {
        return $this->application;
    }

    public function setApplication(?PartnerApplication $application): self
    {
        $this->application = $application;

        return $this;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function setSecretHash(string $secretHash): self
    {
        $this->secretHash = $secretHash;

        return $this;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getStatus(): CredentialStatus
    {
        return $this->status;
    }

    public function setStatus(CredentialStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function getRevokedBy(): ?Utilisateur
    {
        return $this->revokedBy;
    }

    public function setRevokedBy(?Utilisateur $revokedBy): self
    {
        $this->revokedBy = $revokedBy;

        return $this;
    }
}
