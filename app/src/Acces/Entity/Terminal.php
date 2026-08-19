<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\StatutTerminal;
use App\Acces\State\EnrolerTerminalProcessor;
use App\Acces\State\RevoquerTerminalProcessor;
use App\Acces\State\RotationJetonTerminalProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Identité machine authentifiée d'une borne/concentrateur ITBOX (US-TERM-01/09, plan-acces-terminal.md
 * §1.1). Couvre nativement tous les `Controleur`/`Equipement` partageant son `itboxRef` au sein du même
 * établissement (portée = l'ITBOX, décision proposée §4.1 spec). N'expose jamais de secret : le jeton
 * (`JetonTerminal`) est hashé, jamais restitué en clair après émission (RG-SOCLE-06).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_terminal')]
#[ORM\Index(columns: ['itbox_ref'], name: 'idx_terminal_itbox_ref')]
#[ORM\Index(columns: ['etablissement_id'], name: 'idx_terminal_etablissement')]
/*
 * Durcissement revue sécurité (double-enrôlement non révocable) : un même matériel (`itboxRef`) ne
 * peut jamais porter plus d'un `Terminal` au sein d'un même établissement — sinon deux `JetonTerminal`
 * valides simultanément pour le même matériel, et révoquer l'un ne coupe pas l'autre. Contrainte
 * unique en base (filet de sécurité, garantit l'invariant même en cas de course) doublée d'un contrôle
 * applicatif explicite (409) dans `EnrolerTerminalProcessor` (message clair, pas une erreur SQL brute).
 */
#[ORM\UniqueConstraint(name: 'uniq_terminal_itbox_etablissement', columns: ['itbox_ref', 'etablissement_id'])]
#[ApiResource(
    shortName: 'Terminal',
    operations: [
        new GetCollection(
            uriTemplate: '/acces/terminaux',
            security: "is_granted('PERM', 'acces.superviser') or is_granted('PERM', 'acces.gerer')",
        ),
        new Get(
            uriTemplate: '/acces/terminaux/{id}',
            security: "is_granted('PERM', 'acces.superviser') or is_granted('PERM', 'acces.gerer')",
        ),
        new Post(
            uriTemplate: '/acces/terminaux',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.gerer')",
            processor: EnrolerTerminalProcessor::class,
        ),
        new Post(
            uriTemplate: '/acces/terminaux/{id}/jetons',
            read: true,
            input: false,
            security: "is_granted('PERM', 'acces.gerer')",
            processor: RotationJetonTerminalProcessor::class,
        ),
        new Post(
            uriTemplate: '/acces/terminaux/{id}/revoquer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'acces.gerer')",
            processor: RevoquerTerminalProcessor::class,
            normalizationContext: ['groups' => ['terminal:read']],
        ),
    ],
    normalizationContext: ['groups' => ['terminal:read']],
)]
class Terminal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['terminal:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Groups(['terminal:read'])]
    private string $nom = '';

    /** Aligné `Controleur.itboxRef` — portée = tous les `Controleur`/`Equipement` de ce concentrateur. */
    #[ORM\Column(length: 128)]
    #[Groups(['terminal:read'])]
    private string $itboxRef = '';

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['terminal:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 12, enumType: StatutTerminal::class, options: ['default' => 'actif'])]
    #[Groups(['terminal:read'])]
    private StatutTerminal $statut = StatutTerminal::Actif;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['terminal:read'])]
    private ?\DateTimeImmutable $dernierAppel = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['terminal:read'])]
    private ?bool $dernierAppelReussi = null;

    /** Curseur delta le plus récent servi (traçabilité, pas source de vérité côté serveur). */
    #[ORM\Column(nullable: true)]
    #[Groups(['terminal:read'])]
    private ?int $dernierSnapshotVersion = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['terminal:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getItboxRef(): string
    {
        return $this->itboxRef;
    }

    public function setItboxRef(string $itboxRef): self
    {
        $this->itboxRef = $itboxRef;

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

    public function getStatut(): StatutTerminal
    {
        return $this->statut;
    }

    public function setStatut(StatutTerminal $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDernierAppel(): ?\DateTimeImmutable
    {
        return $this->dernierAppel;
    }

    public function setDernierAppel(?\DateTimeImmutable $dernierAppel): self
    {
        $this->dernierAppel = $dernierAppel;

        return $this;
    }

    public function isDernierAppelReussi(): ?bool
    {
        return $this->dernierAppelReussi;
    }

    public function setDernierAppelReussi(?bool $dernierAppelReussi): self
    {
        $this->dernierAppelReussi = $dernierAppelReussi;

        return $this;
    }

    public function getDernierSnapshotVersion(): ?int
    {
        return $this->dernierSnapshotVersion;
    }

    public function setDernierSnapshotVersion(?int $dernierSnapshotVersion): self
    {
        $this->dernierSnapshotVersion = $dernierSnapshotVersion;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
