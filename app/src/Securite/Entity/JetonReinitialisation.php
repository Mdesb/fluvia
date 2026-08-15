<?php

declare(strict_types=1);

namespace App\Securite\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Jeton de réinitialisation de mot de passe (US-L0-03 différée, CA-6). Usage unique, durée
 * limitée. Entité interne : pas de `#[ApiResource]`, créée/consommée uniquement par les
 * contrôleurs dédiés `DemandeReinitialisationController`/`ReinitialisationMotDePasseController`.
 * Non ajoutée à `AuditWriteSubscriber::CLASSES_SURVEILLEES` (donnée sensible mais transitoire).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sec_jeton_reinitialisation')]
#[ORM\UniqueConstraint(name: 'uniq_jeton_reinitialisation', columns: ['jeton'])]
class JetonReinitialisation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $utilisateur = null;

    /** Jeton haché (sha256) — jamais stocké en clair. */
    #[ORM\Column(length: 255, unique: true)]
    private string $jeton = '';

    #[ORM\Column]
    private \DateTimeImmutable $dateExpiration;

    #[ORM\Column(options: ['default' => false])]
    private bool $utilise = false;

    #[ORM\Column]
    private \DateTimeImmutable $dateCreation;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateExpiration = new \DateTimeImmutable('+1 hour');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    public function setJeton(string $jeton): self
    {
        $this->jeton = $jeton;

        return $this;
    }

    public function getDateExpiration(): \DateTimeImmutable
    {
        return $this->dateExpiration;
    }

    public function setDateExpiration(\DateTimeImmutable $dateExpiration): self
    {
        $this->dateExpiration = $dateExpiration;

        return $this;
    }

    public function isUtilise(): bool
    {
        return $this->utilise;
    }

    public function setUtilise(bool $utilise): self
    {
        $this->utilise = $utilise;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function estValide(\DateTimeImmutable $maintenant): bool
    {
        return !$this->utilise && $this->dateExpiration > $maintenant;
    }
}
