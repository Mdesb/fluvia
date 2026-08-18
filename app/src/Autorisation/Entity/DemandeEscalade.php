<?php

declare(strict_types=1);

namespace App\Autorisation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Autorisation\Enum\StatutEscalade;
use App\Autorisation\State\ApprouverEscaladeProcessor;
use App\Autorisation\State\RejeterEscaladeProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Demande d'escalade (RG-AUTZ-06/07) : créée par `ServiceAutorisation` (jamais via l'API publique,
 * pas d'opération `Post` de création) quand une opération sensible dépasse le plafond configuré et
 * que `escaladeAuDela = true`. `jeton` (rejeu, §0 n°5 du plan) est distinct de `id` (PK interne
 * utilisée par les endpoints superviseur). `dateRejeu` (§0 n°6, additif) empêche le rejeu multiple
 * d'un jeton approuvé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'atz_demande_escalade')]
#[ORM\Index(name: 'idx_escalade_operation_cible', columns: ['operation_code', 'cible_id'])]
#[ORM\Index(name: 'idx_escalade_auteur', columns: ['auteur_id'])]
#[ORM\Index(name: 'idx_escalade_etablissement', columns: ['etablissement_id'])]
#[ORM\Index(name: 'idx_escalade_etablissement_statut', columns: ['etablissement_id', 'statut'])]
#[ORM\Index(name: 'idx_escalade_statut_expiration', columns: ['statut', 'date_expiration'])]
#[ApiResource(
    shortName: 'DemandeEscalade',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'autorisation.approuver') or is_granted('PERM', 'autorisation.lire')"),
        new Get(security: "is_granted('PERM', 'autorisation.approuver') or is_granted('PERM', 'autorisation.lire') or object.getAuteur() == user"),
        new Post(
            uriTemplate: '/demandes-escalade/{id}/approuver',
            read: true,
            input: false,
            security: "is_granted('PERM', 'autorisation.approuver')",
            processor: ApprouverEscaladeProcessor::class,
        ),
        new Post(
            uriTemplate: '/demandes-escalade/{id}/rejeter',
            read: true,
            input: false,
            security: "is_granted('PERM', 'autorisation.approuver')",
            processor: RejeterEscaladeProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['escalade:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['statut' => 'exact', 'operation' => 'exact', 'etablissement' => 'exact', 'auteur' => 'exact'])]
class DemandeEscalade
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['escalade:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: OperationSensible::class)]
    #[ORM\JoinColumn(name: 'operation_code', referencedColumnName: 'code', nullable: false)]
    #[Groups(['escalade:read'])]
    private ?OperationSensible $operation = null;

    #[ORM\Column(length: 60)]
    #[Groups(['escalade:read'])]
    private string $cibleType = '';

    #[ORM\Column(length: 36)]
    #[Groups(['escalade:read'])]
    private string $cibleId = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['escalade:read'])]
    private string $montant = '0.00';

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['escalade:read'])]
    private ?Utilisateur $auteur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['escalade:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 12, enumType: StatutEscalade::class)]
    #[Groups(['escalade:read'])]
    private StatutEscalade $statut = StatutEscalade::EnAttente;

    #[ORM\Column]
    #[Groups(['escalade:read'])]
    private \DateTimeImmutable $dateDemande;

    #[ORM\Column]
    #[Groups(['escalade:read'])]
    private \DateTimeImmutable $dateExpiration;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['escalade:read'])]
    private ?Utilisateur $superviseur = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['escalade:read'])]
    private ?\DateTimeImmutable $dateTraitement = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['escalade:read'])]
    private ?string $motifRejet = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['escalade:read'])]
    private Uuid $jeton;

    /** Renseigné à la première exécution réussie du rejeu (§0 n°6, additif) : jeton à usage unique. */
    #[ORM\Column(nullable: true)]
    #[Groups(['escalade:read'])]
    private ?\DateTimeImmutable $dateRejeu = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->jeton = Uuid::v4();
        $this->dateDemande = new \DateTimeImmutable();
        $this->dateExpiration = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOperation(): ?OperationSensible
    {
        return $this->operation;
    }

    public function setOperation(?OperationSensible $operation): self
    {
        $this->operation = $operation;

        return $this;
    }

    public function getCibleType(): string
    {
        return $this->cibleType;
    }

    public function setCibleType(string $cibleType): self
    {
        $this->cibleType = $cibleType;

        return $this;
    }

    public function getCibleId(): string
    {
        return $this->cibleId;
    }

    public function setCibleId(string $cibleId): self
    {
        $this->cibleId = $cibleId;

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

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

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

    public function getStatut(): StatutEscalade
    {
        return $this->statut;
    }

    public function setStatut(StatutEscalade $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateDemande(): \DateTimeImmutable
    {
        return $this->dateDemande;
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

    public function getSuperviseur(): ?Utilisateur
    {
        return $this->superviseur;
    }

    public function setSuperviseur(?Utilisateur $superviseur): self
    {
        $this->superviseur = $superviseur;

        return $this;
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

    public function getMotifRejet(): ?string
    {
        return $this->motifRejet;
    }

    public function setMotifRejet(?string $motifRejet): self
    {
        $this->motifRejet = $motifRejet;

        return $this;
    }

    public function getJeton(): Uuid
    {
        return $this->jeton;
    }

    public function getDateRejeu(): ?\DateTimeImmutable
    {
        return $this->dateRejeu;
    }

    public function setDateRejeu(?\DateTimeImmutable $dateRejeu): self
    {
        $this->dateRejeu = $dateRejeu;

        return $this;
    }

    /** Active, non expirée à l'instant présent (double garde, cf. commande d'expiration, RG-AUTZ-07). */
    public function estEnAttenteMaintenant(\DateTimeImmutable $maintenant): bool
    {
        return $this->statut === StatutEscalade::EnAttente && $this->dateExpiration > $maintenant;
    }
}
