<?php

declare(strict_types=1);

namespace App\Caisse\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Caisse\Enum\EtatSession;
use App\Caisse\State\CloturerSessionProcessor;
use App\Caisse\State\OuvrirSessionProcessor;
use App\Caisse\State\RouvrirCaisseProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Session de caisse (régie) : aucune vente sans session ouverte (RG-M2-01). Exige point de vente,
 * fond de caisse et régisseur identifié (code validé). Une seule session active par point de vente
 * (CA-1) : garantie par la colonne `pdvActif` (= id du PDV tant que la session n'est pas close,
 * null après clôture) sous index unique. La clôture Z fige la session (RG-M2-06 / CA-14).
 */
#[ORM\Entity]
#[ORM\Table(name: 'caisse_session')]
#[ORM\UniqueConstraint(name: 'uniq_session_numero', columns: ['numero'])]
#[ORM\UniqueConstraint(name: 'uniq_session_active_pdv', columns: ['pdv_actif'])]
#[ApiResource(
    shortName: 'SessionCaisse',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caisse.lire') or is_granted('PERM', 'vente.lire')"),
        new Get(security: "is_granted('PERM', 'caisse.lire') or is_granted('PERM', 'vente.lire')"),
        new Post(
            uriTemplate: '/sessions-caisse/ouvrir',
            read: false,
            input: false,
            security: "is_granted('PERM', 'caisse.ouvrir')",
            processor: OuvrirSessionProcessor::class,
        ),
        new Post(
            uriTemplate: '/sessions-caisse/{id}/cloturer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'caisse.cloturer')",
            processor: CloturerSessionProcessor::class,
        ),
        new Post(
            uriTemplate: '/sessions-caisse/{id}/rouvrir',
            read: true,
            input: false,
            security: "is_granted('PERM', 'caisse.ouvrir')",
            processor: RouvrirCaisseProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['session:read']],
)]
class SessionCaisse
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['session:read', 'vente:read', 'mouvement:read', 'cloture:read'])]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    #[Groups(['session:read', 'vente:read'])]
    private string $numero = '';

    #[ORM\ManyToOne(targetEntity: PointDeVente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['session:read'])]
    private ?PointDeVente $pointDeVente = null;

    #[ORM\ManyToOne(targetEntity: Caisse::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['session:read'])]
    private ?Caisse $caisse = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['session:read'])]
    private ?Utilisateur $regisseur = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['session:read'])]
    private ?Utilisateur $operateur = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['session:read'])]
    private string $fondDeCaisse = '0.00';

    #[ORM\Column(length: 16, enumType: EtatSession::class, options: ['default' => 'ouverte'])]
    #[Groups(['session:read'])]
    private EtatSession $etat = EtatSession::Ouverte;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['session:read'])]
    private \DateTimeImmutable $ouvertureLe;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['session:read'])]
    private ?\DateTimeImmutable $fermetureLe = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['session:read'])]
    private ?Etablissement $etablissement = null;

    /**
     * Discriminant d'unicité « une session active par point de vente » (CA-1) : porte l'id du PDV
     * tant que la session n'est pas close, null après clôture. Sous index unique.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $pdvActif = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->ouvertureLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getPointDeVente(): ?PointDeVente
    {
        return $this->pointDeVente;
    }

    public function setPointDeVente(?PointDeVente $pointDeVente): self
    {
        $this->pointDeVente = $pointDeVente;
        $this->pdvActif = $pointDeVente?->getId();

        return $this;
    }

    public function getCaisse(): ?Caisse
    {
        return $this->caisse;
    }

    public function setCaisse(?Caisse $caisse): self
    {
        $this->caisse = $caisse;

        return $this;
    }

    public function getRegisseur(): ?Utilisateur
    {
        return $this->regisseur;
    }

    public function setRegisseur(?Utilisateur $regisseur): self
    {
        $this->regisseur = $regisseur;

        return $this;
    }

    public function getOperateur(): ?Utilisateur
    {
        return $this->operateur;
    }

    public function setOperateur(?Utilisateur $operateur): self
    {
        $this->operateur = $operateur;

        return $this;
    }

    public function getFondDeCaisse(): string
    {
        return $this->fondDeCaisse;
    }

    public function setFondDeCaisse(string $fondDeCaisse): self
    {
        $this->fondDeCaisse = $fondDeCaisse;

        return $this;
    }

    public function getEtat(): EtatSession
    {
        return $this->etat;
    }

    public function setEtat(EtatSession $etat): self
    {
        $this->etat = $etat;

        return $this;
    }

    public function getOuvertureLe(): \DateTimeImmutable
    {
        return $this->ouvertureLe;
    }

    public function getFermetureLe(): ?\DateTimeImmutable
    {
        return $this->fermetureLe;
    }

    public function setFermetureLe(?\DateTimeImmutable $fermetureLe): self
    {
        $this->fermetureLe = $fermetureLe;

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

    public function estOuverte(): bool
    {
        return $this->etat === EtatSession::Ouverte;
    }

    /** Ferme définitivement la session (clôture Z) : libère le discriminant d'unicité du PDV. */
    public function fermer(): self
    {
        $this->etat = EtatSession::Close;
        $this->pdvActif = null;
        $this->fermetureLe = new \DateTimeImmutable();

        return $this;
    }
}
