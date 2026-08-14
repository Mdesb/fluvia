<?php

declare(strict_types=1);

namespace App\Caisse\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Caisse\Enum\TypeMouvement;
use App\Caisse\State\MouvementCaisseProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Mouvement d'espèces sur une session (US-L2-10, cahier M2-§8) : entrée/sortie, apport, retrait,
 * versement au comptable. Un gros retrait déclenche une alerte régisseur. Permission caisse.mouvement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'caisse_mouvement')]
#[ApiResource(
    shortName: 'MouvementCaisse',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caisse.lire')"),
        new Get(security: "is_granted('PERM', 'caisse.lire')"),
        new Post(
            uriTemplate: '/mouvements-caisse',
            read: false,
            input: false,
            security: "is_granted('PERM', 'caisse.mouvement')",
            processor: MouvementCaisseProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['mouvement:read']],
)]
class MouvementCaisse
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mouvement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SessionCaisse::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement:read'])]
    private ?SessionCaisse $session = null;

    #[ORM\Column(length: 16, enumType: TypeMouvement::class)]
    #[Groups(['mouvement:read'])]
    private TypeMouvement $type = TypeMouvement::Entree;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['mouvement:read'])]
    private string $montant = '0.00';

    #[ORM\Column(length: 255)]
    #[Groups(['mouvement:read'])]
    private string $motif = '';

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mouvement:read'])]
    private ?Utilisateur $auteur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['mouvement:read'])]
    private \DateTimeImmutable $dateHeure;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['mouvement:read'])]
    private bool $alerteRegisseur = false;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateHeure = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSession(): ?SessionCaisse
    {
        return $this->session;
    }

    public function setSession(?SessionCaisse $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function getType(): TypeMouvement
    {
        return $this->type;
    }

    public function setType(TypeMouvement $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getDateHeure(): \DateTimeImmutable
    {
        return $this->dateHeure;
    }

    public function isAlerteRegisseur(): bool
    {
        return $this->alerteRegisseur;
    }

    public function setAlerteRegisseur(bool $alerteRegisseur): self
    {
        $this->alerteRegisseur = $alerteRegisseur;

        return $this;
    }
}
