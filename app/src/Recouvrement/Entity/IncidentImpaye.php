<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Enum\CanalResolutionImpaye;
use App\Recouvrement\Enum\StatutIncidentImpaye;
use App\Recouvrement\Security\RedevableSoiVoter;
use App\Recouvrement\State\ForcerReouvertureProcessor;
use App\Recouvrement\State\ResoudreImpayeProcessor;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Entity\RejetSepa;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Dossier impayé — moteur générique partagé (extrait de `App\Sport\Entity\IncidentPrelevement`),
 * réutilisable par toute activité à abonnement. Le contrat en cause n'est PAS référencé par une
 * association Doctrine vers une verticale (Recouvrement ne dépend d'aucune verticale) : il est désigné
 * par le couple opaque `typeRedevable`/`referenceRedevable`, résolu par
 * `App\Recouvrement\Service\RedevableRegistry` (port `RedevablePort` fourni par la verticale). Quand un
 * `RejetSepa` (module SEPA partagé) existe réellement (flux remise → retour banque complet), il est
 * référencé ici (`rejetOrigine`) — convergence documentée dans le rapport de refactor ; à défaut
 * (simulation/saisie manuelle d'un retour hors remise), les champs bruts (`motifBancaire`, etc.) sont
 * renseignés directement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recouvrement_incident_impaye')]
#[ApiResource(
    shortName: 'IncidentImpaye',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'recouvrement.piloter')"),
        new Get(security: "is_granted('PERM', 'recouvrement.piloter') or (is_granted('PERM', 'recouvrement.lire_soi') and is_granted('" . RedevableSoiVoter::ATTRIBUTE . "', object))"),
        new Post(
            uriTemplate: '/recouvrement/incidents/{id}/resoudre',
            read: true,
            input: false,
            security: "is_granted('PERM', 'recouvrement.piloter') or (is_granted('PERM', 'recouvrement.resoudre_impaye_soi') and is_granted('" . RedevableSoiVoter::ATTRIBUTE . "', object))",
            processor: ResoudreImpayeProcessor::class,
        ),
        new Post(
            uriTemplate: '/recouvrement/incidents/{id}/forcer-reouverture',
            read: true,
            input: false,
            security: "is_granted('PERM', 'recouvrement.forcer_acces')",
            processor: ForcerReouvertureProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['incident:read']],
)]
class IncidentImpaye
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['incident:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['incident:read'])]
    private ?Etablissement $etablissement = null;

    /** Type de contrat porté par la verticale (ex. `sport.abonnement_fitness`), résolu via `RedevableRegistry`. */
    #[ORM\Column(length: 60)]
    #[Groups(['incident:read'])]
    private string $typeRedevable = '';

    /** Identifiant opaque du contrat côté verticale (ex. l'UUID d'un `AbonnementFitness`). */
    #[ORM\Column(length: 64)]
    #[Groups(['incident:read'])]
    private string $referenceRedevable = '';

    /** Identifiant opaque de l'échéance d'origine côté verticale, si connu. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['incident:read'])]
    private ?string $referenceEcheanceOrigine = null;

    /** Convergence vers le module SEPA partagé (module `App\Sepa`), quand le rejet provient d'une remise réelle. */
    #[ORM\ManyToOne(targetEntity: RejetSepa::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['incident:read'])]
    private ?RejetSepa $rejetOrigine = null;

    #[ORM\Column]
    #[Groups(['incident:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['incident:read'])]
    private \DateTimeImmutable $dateRejet;

    #[ORM\Column(length: 4)]
    #[Groups(['incident:read'])]
    private string $motifBancaire = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['incident:read'])]
    private ?string $libelleMotifBancaire = null;

    #[ORM\Column(length: 14, enumType: StatutIncidentImpaye::class, options: ['default' => 'representation'])]
    #[Groups(['incident:read'])]
    private StatutIncidentImpaye $statut = StatutIncidentImpaye::Representation;

    #[ORM\Column(length: 10, nullable: true, enumType: CanalResolutionImpaye::class)]
    #[Groups(['incident:read'])]
    private ?CanalResolutionImpaye $canalResolution = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['incident:read'])]
    private ?\DateTimeImmutable $dateResolution = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['incident:read'])]
    private ?Utilisateur $reouvertureForceePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['incident:read'])]
    private ?string $motifReouvertureForcee = null;

    /** Vrai si l'accès du redevable est actuellement bloqué à cause de ce dossier (tableau de bord). */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['incident:read'])]
    private bool $accesBloque = false;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getTypeRedevable(): string
    {
        return $this->typeRedevable;
    }

    public function setTypeRedevable(string $typeRedevable): self
    {
        $this->typeRedevable = $typeRedevable;

        return $this;
    }

    public function getReferenceRedevable(): string
    {
        return $this->referenceRedevable;
    }

    public function setReferenceRedevable(string $referenceRedevable): self
    {
        $this->referenceRedevable = $referenceRedevable;

        return $this;
    }

    public function getReferenceEcheanceOrigine(): ?string
    {
        return $this->referenceEcheanceOrigine;
    }

    public function setReferenceEcheanceOrigine(?string $referenceEcheanceOrigine): self
    {
        $this->referenceEcheanceOrigine = $referenceEcheanceOrigine;

        return $this;
    }

    public function getRejetOrigine(): ?RejetSepa
    {
        return $this->rejetOrigine;
    }

    public function setRejetOrigine(?RejetSepa $rejetOrigine): self
    {
        $this->rejetOrigine = $rejetOrigine;

        return $this;
    }

    public function getMontantCentimes(): int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }

    public function getDateRejet(): \DateTimeImmutable
    {
        return $this->dateRejet;
    }

    public function setDateRejet(\DateTimeImmutable $dateRejet): self
    {
        $this->dateRejet = $dateRejet;

        return $this;
    }

    public function getMotifBancaire(): string
    {
        return $this->motifBancaire;
    }

    public function setMotifBancaire(string $motifBancaire): self
    {
        $this->motifBancaire = $motifBancaire;

        return $this;
    }

    public function getLibelleMotifBancaire(): ?string
    {
        return $this->libelleMotifBancaire;
    }

    public function setLibelleMotifBancaire(?string $libelleMotifBancaire): self
    {
        $this->libelleMotifBancaire = $libelleMotifBancaire;

        return $this;
    }

    public function getStatut(): StatutIncidentImpaye
    {
        return $this->statut;
    }

    public function setStatut(StatutIncidentImpaye $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getCanalResolution(): ?CanalResolutionImpaye
    {
        return $this->canalResolution;
    }

    public function setCanalResolution(?CanalResolutionImpaye $canalResolution): self
    {
        $this->canalResolution = $canalResolution;

        return $this;
    }

    public function getDateResolution(): ?\DateTimeImmutable
    {
        return $this->dateResolution;
    }

    public function setDateResolution(?\DateTimeImmutable $dateResolution): self
    {
        $this->dateResolution = $dateResolution;

        return $this;
    }

    public function getReouvertureForceePar(): ?Utilisateur
    {
        return $this->reouvertureForceePar;
    }

    public function setReouvertureForceePar(?Utilisateur $reouvertureForceePar): self
    {
        $this->reouvertureForceePar = $reouvertureForceePar;

        return $this;
    }

    public function getMotifReouvertureForcee(): ?string
    {
        return $this->motifReouvertureForcee;
    }

    public function setMotifReouvertureForcee(?string $motifReouvertureForcee): self
    {
        $this->motifReouvertureForcee = $motifReouvertureForcee;

        return $this;
    }

    public function isAccesBloque(): bool
    {
        return $this->accesBloque;
    }

    public function setAccesBloque(bool $accesBloque): self
    {
        $this->accesBloque = $accesBloque;

        return $this;
    }
}
