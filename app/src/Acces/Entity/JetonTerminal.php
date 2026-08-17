<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use App\Acces\Enum\StatutJetonTerminal;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Jeton d'authentification d'un `Terminal` (US-TERM-01/09, plan-acces-terminal.md §1.1) : 1 `Terminal`
 * peut porter plusieurs jetons dans le temps (rotation). `secretHash` = `hash('sha256', secret)` —
 * SHA-256 déterministe (recherche indexée en O(1)), **pas** bcrypt (Risque R-1, cf. plan §8) : le
 * secret a une forte entropie native (généré côté serveur, jamais choisi par un humain).
 *
 * Aucune opération API Platform n'expose directement cette entité (pas d'`#[ApiResource]`) : elle est
 * gérée exclusivement via les opérations `Terminal` (enrôlement/rotation/révocation, §2.1 du plan) et
 * résolue en interne par `App\Acces\Security\TerminalAuthenticator`. `secretHash` n'est **jamais**
 * exposé (aucun getter public en API, aucun groupe de sérialisation).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_jeton_terminal')]
#[ORM\UniqueConstraint(name: 'uniq_jeton_secret_hash', columns: ['secret_hash'])]
#[ORM\Index(columns: ['terminal_id'], name: 'idx_jeton_terminal')]
class JetonTerminal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Terminal::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Terminal $terminal = null;

    #[ORM\Column(length: 64)]
    private string $secretHash = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateEmission;

    /** Politique d'expiration non tranchée (§8 spec pt.3) ; `null` = pas d'expiration MVP. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateExpiration = null;

    #[ORM\Column(length: 12, enumType: StatutJetonTerminal::class, options: ['default' => 'actif'])]
    private StatutJetonTerminal $statut = StatutJetonTerminal::Actif;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revoqueLe = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $revoquePar = null;

    /** Dénormalisé de `terminal.etablissement`, pour `PerimetreAccesExtension` (RG-SOCLE-05). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateEmission = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTerminal(): ?Terminal
    {
        return $this->terminal;
    }

    public function setTerminal(?Terminal $terminal): self
    {
        $this->terminal = $terminal;

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

    public function getDateEmission(): \DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function setDateEmission(\DateTimeImmutable $dateEmission): self
    {
        $this->dateEmission = $dateEmission;

        return $this;
    }

    public function getDateExpiration(): ?\DateTimeImmutable
    {
        return $this->dateExpiration;
    }

    public function setDateExpiration(?\DateTimeImmutable $dateExpiration): self
    {
        $this->dateExpiration = $dateExpiration;

        return $this;
    }

    public function getStatut(): StatutJetonTerminal
    {
        return $this->statut;
    }

    public function setStatut(StatutJetonTerminal $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getRevoqueLe(): ?\DateTimeImmutable
    {
        return $this->revoqueLe;
    }

    public function setRevoqueLe(?\DateTimeImmutable $revoqueLe): self
    {
        $this->revoqueLe = $revoqueLe;

        return $this;
    }

    public function getRevoquePar(): ?Utilisateur
    {
        return $this->revoquePar;
    }

    public function setRevoquePar(?Utilisateur $revoquePar): self
    {
        $this->revoquePar = $revoquePar;

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
