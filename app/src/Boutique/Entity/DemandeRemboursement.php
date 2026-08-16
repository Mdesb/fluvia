<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Boutique\Enum\StatutDemandeRemboursement;
use App\Boutique\Security\DemandeRemboursementSoiVoter;
use App\Boutique\State\AccepterDemandeRemboursementProcessor;
use App\Boutique\State\DeposerDemandeRemboursementProcessor;
use App\Boutique\State\RefuserDemandeRemboursementProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Demande de remboursement en ligne (US-L8-12, RG-M3-15). Aucun remboursement automatique : passe
 * par ce formulaire, traité par un opérateur habilité (`boutique.traiter_remboursement`) qui accepte
 * (avoir M2 via `ContrePassationHandler`, réutilisé) ou refuse (motif communiqué). `origineAutomatique`
 * trace le cas de conflit d'inventaire à la confirmation (§0 décision n°8 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_demande_remboursement')]
#[ApiResource(
    shortName: 'DemandeRemboursement',
    operations: [
        new GetCollection(uriTemplate: '/boutique/demandes-remboursement', security: "is_granted('PERM', 'boutique.lire')"),
        new Get(uriTemplate: '/boutique/demandes-remboursement/{id}', security: "is_granted('PERM', 'boutique.lire') or (is_granted('PERM', 'boutique.demander_remboursement_soi') and is_granted('" . DemandeRemboursementSoiVoter::ATTRIBUTE . "', object))"),
        new Post(
            uriTemplate: '/boutique/demandes-remboursement',
            read: false,
            input: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY') and is_granted('PERM', 'boutique.demander_remboursement_soi')",
            processor: DeposerDemandeRemboursementProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/demandes-remboursement/{id}/accepter',
            read: true,
            input: false,
            security: "is_granted('PERM', 'boutique.traiter_remboursement')",
            processor: AccepterDemandeRemboursementProcessor::class,
        ),
        new Post(
            uriTemplate: '/boutique/demandes-remboursement/{id}/refuser',
            read: true,
            input: false,
            security: "is_granted('PERM', 'boutique.traiter_remboursement')",
            processor: RefuserDemandeRemboursementProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['demande_remb:read']],
)]
class DemandeRemboursement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['demande_remb:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['demande_remb:read'])]
    private ?Vente $vente = null;

    #[ORM\ManyToOne(targetEntity: LigneVente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['demande_remb:read'])]
    private ?LigneVente $ligne = null;

    #[ORM\Column(type: 'text')]
    #[Groups(['demande_remb:read'])]
    private string $motif = '';

    /** @var list<string>|null chemins de fichiers */
    #[ORM\Column(nullable: true)]
    #[Groups(['demande_remb:read'])]
    private ?array $piecesJustificatives = null;

    #[ORM\Column(length: 10, enumType: StatutDemandeRemboursement::class, options: ['default' => 'recue'])]
    #[Groups(['demande_remb:read'])]
    private StatutDemandeRemboursement $statut = StatutDemandeRemboursement::Recue;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['demande_remb:read'])]
    private bool $origineAutomatique = false;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['demande_remb:read'])]
    private \DateTimeImmutable $dateDemande;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['demande_remb:read'])]
    private ?\DateTimeImmutable $dateTraitement = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['demande_remb:read'])]
    private ?Utilisateur $traitePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['demande_remb:read'])]
    private ?string $motifRefus = null;

    #[ORM\ManyToOne(targetEntity: Avoir::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['demande_remb:read'])]
    private ?Avoir $avoirRattache = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateDemande = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVente(): ?Vente
    {
        return $this->vente;
    }

    public function setVente(?Vente $vente): self
    {
        $this->vente = $vente;

        return $this;
    }

    public function getLigne(): ?LigneVente
    {
        return $this->ligne;
    }

    public function setLigne(?LigneVente $ligne): self
    {
        $this->ligne = $ligne;

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

    /** @return list<string>|null */
    public function getPiecesJustificatives(): ?array
    {
        return $this->piecesJustificatives;
    }

    /** @param list<string>|null $piecesJustificatives */
    public function setPiecesJustificatives(?array $piecesJustificatives): self
    {
        $this->piecesJustificatives = $piecesJustificatives;

        return $this;
    }

    public function getStatut(): StatutDemandeRemboursement
    {
        return $this->statut;
    }

    public function setStatut(StatutDemandeRemboursement $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function isOrigineAutomatique(): bool
    {
        return $this->origineAutomatique;
    }

    public function setOrigineAutomatique(bool $origineAutomatique): self
    {
        $this->origineAutomatique = $origineAutomatique;

        return $this;
    }

    public function getDateDemande(): \DateTimeImmutable
    {
        return $this->dateDemande;
    }

    public function getDateTraitement(): ?\DateTimeImmutable
    {
        return $this->dateTraitement;
    }

    public function setDateTraitement(?\DateTimeImmutable $dateTraitement): self
    {
        $this->dateTraitement = $dateTraitement;

        return $this;
    }

    public function getTraitePar(): ?Utilisateur
    {
        return $this->traitePar;
    }

    public function setTraitePar(?Utilisateur $traitePar): self
    {
        $this->traitePar = $traitePar;

        return $this;
    }

    public function getMotifRefus(): ?string
    {
        return $this->motifRefus;
    }

    public function setMotifRefus(?string $motifRefus): self
    {
        $this->motifRefus = $motifRefus;

        return $this;
    }

    public function getAvoirRattache(): ?Avoir
    {
        return $this->avoirRattache;
    }

    public function setAvoirRattache(?Avoir $avoirRattache): self
    {
        $this->avoirRattache = $avoirRattache;

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
