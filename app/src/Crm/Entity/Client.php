<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\Crm\State\ClientEcritureProcessor;
use App\Crm\State\EnregistrerConsentementProcessor;
use App\Crm\State\FicheClient360Provider;
use App\Crm\State\PmvMouvementsProvider;
use App\Crm\State\PmvProvider;
use App\Crm\State\PmvRechargerProcessor;
use App\Crm\State\RattacherSupportProcessor;
use App\Crm\State\RechercheClientProvider;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fiche client unique (US-L5-01/02, RG-M4-01). Seule entité du module M4 à porter des données
 * nominatives (§1 plan-crm.md) : l'anonymisation RGPD (RG-M4-09) se limite donc à purger ses champs,
 * sans cascade. Portée par le **Groupe** (§10.1 plan-crm.md, ⚠ HYPOTHÈSE retenue) pour éviter les
 * doublons multi-sites ; l'établissement de création reste tracé pour audit, sans conditionner le
 * cloisonnement (`App\Crm\Doctrine\PerimetreCrmExtension`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_client')]
#[ApiResource(
    shortName: 'Client',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.lire')", normalizationContext: ['groups' => ['client:list']]),
        new Get(security: "is_granted('PERM', 'crm.lire') or (is_granted('PERM', 'crm.lire_soi') and object.estLieA(user))"),
        new Post(security: "is_granted('PERM', 'crm.creer')", processor: ClientEcritureProcessor::class),
        new Patch(
            security: "is_granted('PERM', 'crm.modifier') or (is_granted('PERM', 'crm.modifier_soi') and object.estLieA(user))",
            processor: ClientEcritureProcessor::class,
        ),
        new Get(
            // NB : le provider renvoie une JsonResponse (agrégation, pas l'entité Client) — `object`
            // n'y est donc pas exploitable pour une vérification `_soi` déclarative ; le contrôle
            // « soi-même » est fait de façon impérative dans `FicheClient360Provider`.
            uriTemplate: '/clients/{id}/fiche-360',
            security: "is_granted('PERM', 'crm.lire') or is_granted('PERM', 'crm.lire_soi')",
            provider: FicheClient360Provider::class,
        ),
        new Post(
            uriTemplate: '/clients/{id}/rattacher-support',
            read: true,
            input: false,
            security: "is_granted('PERM', 'crm.creer')",
            processor: RattacherSupportProcessor::class,
        ),
        new Get(
            uriTemplate: '/crm/clients/recherche',
            security: "is_granted('PERM', 'crm.lire')",
            provider: RechercheClientProvider::class,
        ),
        new Get(
            // Même remarque que fiche-360 : provider -> JsonResponse, contrôle `_soi` impératif.
            uriTemplate: '/clients/{id}/pmv',
            read: true,
            security: "is_granted('PERM', 'crm.pmv_lire') or is_granted('PERM', 'crm.pmv_lire_soi')",
            provider: PmvProvider::class,
        ),
        new Get(
            uriTemplate: '/clients/{id}/pmv/mouvements',
            read: true,
            security: "is_granted('PERM', 'crm.pmv_lire') or is_granted('PERM', 'crm.pmv_lire_soi')",
            provider: PmvMouvementsProvider::class,
        ),
        new Post(
            uriTemplate: '/clients/{id}/pmv/recharger',
            read: true,
            input: false,
            security: "is_granted('PERM', 'crm.pmv_recharger') or (is_granted('PERM', 'crm.pmv_recharger_soi') and object.estLieA(user))",
            processor: PmvRechargerProcessor::class,
        ),
        new Post(
            uriTemplate: '/clients/{id}/consentements',
            read: true,
            input: false,
            security: "is_granted('PERM', 'crm.lire') or is_granted('PERM', 'crm.consentement_gerer_soi')",
            processor: EnregistrerConsentementProcessor::class,
            output: Consentement::class,
            normalizationContext: ['groups' => ['consentement:read']],
        ),
    ],
    normalizationContext: ['groups' => ['client:read']],
    denormalizationContext: ['groups' => ['client:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['nom' => 'partial', 'prenom' => 'partial', 'email' => 'partial', 'telephone' => 'partial', 'statut' => 'exact'])]
#[Assert\Callback('validerCoherenceType')]
class Client
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['client:read', 'client:list', 'famille:read', 'beneficiaire:read', 'fiche360:read', 'pmv:read', 'consentement:read', 'rgpd:read', 'fusion:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Groupe::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Groupe $groupe = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['client:read'])]
    private ?Etablissement $etablissementCreation = null;

    #[ORM\Column(length: 12, enumType: TypeClient::class)]
    #[Groups(['client:read', 'client:list', 'client:write', 'fiche360:read'])]
    private TypeClient $type = TypeClient::Physique;

    #[ORM\Column(length: 8, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $civilite = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['client:read', 'client:list', 'client:write', 'fiche360:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['client:read', 'client:list', 'client:write', 'fiche360:read'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['client:read', 'client:list', 'client:write'])]
    private ?string $raisonSociale = null;

    #[ORM\Column(length: 14, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $siret = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['client:read', 'client:write', 'fiche360:read'])]
    private ?string $email = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['client:read', 'client:write', 'fiche360:read'])]
    private ?string $telephone = null;

    /**
     * Moyen de paiement préféré du client (UI-5), renseigné par l'exploitant depuis le back-office.
     * Référence souple par **code** au référentiel `App\Compta\Entity\MoyenPaiement.code` — volontai-
     * rement pas de relation Doctrine / FK cross-module : `App\Crm` ne couple pas `App\Compta` (D2/D8).
     * Aucune validation contre le référentiel pour l'instant ; à durcir plus tard si besoin.
     */
    #[ORM\Column(name: 'preferred_payment_method_code', length: 32, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $preferredPaymentMethodCode = null;

    /** @var array<string, mixed>|null {rue,complement,cp,ville,pays} */
    #[ORM\Column(nullable: true)]
    #[Groups(['client:read', 'client:write', 'fiche360:read'])]
    private ?array $adresse = null;

    /** @var list<string>|null Champs saisis manuellement, jamais écrasés par l'auto-enrichissement (RG-M4-01/11). */
    #[ORM\Column(nullable: true)]
    #[Groups(['client:read'])]
    private ?array $champManuel = null;

    #[ORM\Column(length: 12, enumType: StatutClient::class, options: ['default' => 'actif'])]
    #[Groups(['client:read', 'client:list', 'fiche360:read'])]
    private StatutClient $statut = StatutClient::Actif;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['client:read'])]
    private ?self $fusionneDans = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['client:read', 'fiche360:read'])]
    private ?\DateTimeImmutable $dateDerniereVisite = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true, options: ['default' => '0.00'])]
    #[Groups(['client:read', 'fiche360:read'])]
    private ?string $caCumule = '0.00';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['client:read'])]
    private \DateTimeImmutable $dateCreation;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $creePar = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['client:read'])]
    private ?\DateTimeImmutable $dateMaj = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['client:read'])]
    private ?string $majPar = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGroupe(): ?Groupe
    {
        return $this->groupe;
    }

    public function setGroupe(?Groupe $groupe): self
    {
        $this->groupe = $groupe;

        return $this;
    }

    public function getEtablissementCreation(): ?Etablissement
    {
        return $this->etablissementCreation;
    }

    public function setEtablissementCreation(?Etablissement $etablissementCreation): self
    {
        $this->etablissementCreation = $etablissementCreation;

        return $this;
    }

    public function getType(): TypeClient
    {
        return $this->type;
    }

    public function setType(TypeClient $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCivilite(): ?string
    {
        return $this->civilite;
    }

    public function setCivilite(?string $civilite): self
    {
        $this->civilite = $civilite;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): self
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getRaisonSociale(): ?string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(?string $raisonSociale): self
    {
        $this->raisonSociale = $raisonSociale;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): self
    {
        $this->siret = $siret;

        return $this;
    }

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): self
    {
        $this->dateNaissance = $dateNaissance;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): self
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getPreferredPaymentMethodCode(): ?string
    {
        return $this->preferredPaymentMethodCode;
    }

    public function setPreferredPaymentMethodCode(?string $preferredPaymentMethodCode): self
    {
        $this->preferredPaymentMethodCode = $preferredPaymentMethodCode;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getAdresse(): ?array
    {
        return $this->adresse;
    }

    /** @param array<string, mixed>|null $adresse */
    public function setAdresse(?array $adresse): self
    {
        $this->adresse = $adresse;

        return $this;
    }

    /** @return list<string> */
    public function getChampManuel(): array
    {
        return $this->champManuel ?? [];
    }

    /** @param list<string>|null $champManuel */
    public function setChampManuel(?array $champManuel): self
    {
        $this->champManuel = $champManuel;

        return $this;
    }

    public function marquerChampManuel(string $champ): self
    {
        $champs = $this->getChampManuel();
        if (!\in_array($champ, $champs, true)) {
            $champs[] = $champ;
        }
        $this->champManuel = $champs;

        return $this;
    }

    public function estChampManuel(string $champ): bool
    {
        return \in_array($champ, $this->getChampManuel(), true);
    }

    public function getStatut(): StatutClient
    {
        return $this->statut;
    }

    public function setStatut(StatutClient $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getFusionneDans(): ?self
    {
        return $this->fusionneDans;
    }

    public function setFusionneDans(?self $fusionneDans): self
    {
        $this->fusionneDans = $fusionneDans;

        return $this;
    }

    public function getDateDerniereVisite(): ?\DateTimeImmutable
    {
        return $this->dateDerniereVisite;
    }

    public function setDateDerniereVisite(?\DateTimeImmutable $dateDerniereVisite): self
    {
        $this->dateDerniereVisite = $dateDerniereVisite;

        return $this;
    }

    public function getCaCumule(): ?string
    {
        return $this->caCumule;
    }

    public function setCaCumule(?string $caCumule): self
    {
        $this->caCumule = $caCumule;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): self
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function setCreePar(?Utilisateur $creePar): self
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getDateMaj(): ?\DateTimeImmutable
    {
        return $this->dateMaj;
    }

    public function setDateMaj(?\DateTimeImmutable $dateMaj): self
    {
        $this->dateMaj = $dateMaj;

        return $this;
    }

    public function getMajPar(): ?string
    {
        return $this->majPar;
    }

    public function setMajPar(?string $majPar): self
    {
        $this->majPar = $majPar;

        return $this;
    }

    /** RG-M4-10 : déclenche la vigilance renforcée (consentement représentant, restriction marketing). */
    public function estMineur(): bool
    {
        if ($this->dateNaissance === null) {
            return false;
        }

        return $this->dateNaissance > new \DateTimeImmutable('-18 years');
    }

    #[Groups(['client:read', 'client:list', 'fiche360:read'])]
    public function isEstMineur(): bool
    {
        return $this->estMineur();
    }

    /** Vrai si l'utilisateur connecté (espace client M3) correspond à cette fiche (permissions `_soi`). */
    public function estLieA(mixed $user): bool
    {
        if (!$user instanceof Utilisateur) {
            return false;
        }
        $lie = $user->getClientLie();

        return $lie !== null && (string) $lie === (string) $this->id;
    }

    /** Validation applicative des champs requis selon le type (M4-02, §1.1 plan-crm.md). */
    public function validerCoherenceType(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->type === TypeClient::Physique && ($this->nom === null || trim($this->nom) === '')) {
            $context->buildViolation('Le nom est requis pour un client physique.')->atPath('nom')->addViolation();
        }
        if ($this->type === TypeClient::Morale && ($this->raisonSociale === null || trim($this->raisonSociale) === '')) {
            $context->buildViolation('La raison sociale est requise pour un client moral.')->atPath('raisonSociale')->addViolation();
        }
    }
}
