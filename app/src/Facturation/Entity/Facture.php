<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\FactureB2G;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Enum\CanalFacture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\State\CreerFactureDirecteProcessor;
use App\Facturation\State\DeposerChorusProFactureProcessor;
use App\Facturation\State\EmettreFactureDirecteProcessor;
use App\Facturation\State\EmettreFactureJustificativeProcessor;
use App\Facturation\State\EnregistrerReglementProcessor;
use App\Facturation\State\FactureRenduProvider;
use App\Facturation\State\GenererAvoirFactureProcessor;
use App\Facturation\State\MesFacturesProvider;
use App\Facturation\State\ModifierFactureDirecteProcessor;
use App\Facturation\State\VerifierChaineFactureProvider;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Facture (`plan-facturation.md` §1.1, `spec-facturation.md` §5) : document commercial opposable,
 * émis dans deux circonstances distinctes dont la distinction comptable est le cœur du module
 * (RG-FACT-03) :
 *  - **justificative** (`origine=ticket_encaisse`, `venteOrigine` renseignée) : la `Vente` M2 est
 *    déjà scellée/payée, donc déjà comptabilisée — `ecritureGeneree` reste **`null`**, statut
 *    `acquittee` immédiat ;
 *  - **directe** (`origine=vente_a_terme`) : aucun passage caisse, l'émission **est** le fait
 *    générateur comptable — une seule `EcritureComptable` (M6) est générée, statut
 *    `en_attente_paiement`.
 *
 * Numérotation légale (`FA-…`/`AVF-…`) et chaînage NF525 (empreinte/signature) attribués seulement à
 * l'émission (RG-FACT-01) — un brouillon ne consomme jamais de numéro. Toute correction d'une facture
 * émise passe exclusivement par un **avoir** (RG-FACT-05) ; `FactureInalterableListener` bloque toute
 * autre modification du contenu après scellement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'facturation_facture')]
#[ORM\UniqueConstraint(name: 'uniq_facture_numero', columns: ['numero'])]
#[ORM\UniqueConstraint(name: 'uniq_facture_vente_origine', columns: ['vente_origine_id'])]
#[ORM\UniqueConstraint(name: 'uniq_facturation_facture_corrigee', columns: ['facture_corrigee_id'])]
#[ORM\Index(name: 'idx_facture_chaine', columns: ['profil_exploitant_id', 'nature', 'numero_sequence'])]
#[ApiResource(
    shortName: 'Facture',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'facturation.lire')"),
        new Get(security: "is_granted('PERM', 'facturation.lire') or (is_granted('PERM', 'facturation.lire_soi') and object.estLieA(user))"),
        new GetCollection(
            uriTemplate: '/mes-factures',
            paginationEnabled: false,
            security: "is_granted('PERM', 'facturation.lire_soi')",
            provider: MesFacturesProvider::class,
        ),
        new Get(
            // Le provider renvoie une `JsonResponse` (structure de rendu, pas l'entité `Facture`) :
            // `object` n'y est donc pas exploitable pour une vérification `_soi` déclarative (même
            // remarque que `App\Crm\Entity\Client` sur `/clients/{id}/fiche-360`) — le contrôle
            // « soi-même » est fait de façon impérative dans `FactureRenduProvider`.
            uriTemplate: '/factures/{id}/rendu',
            security: "is_granted('PERM', 'facturation.lire') or is_granted('PERM', 'facturation.lire_soi')",
            provider: FactureRenduProvider::class,
        ),
        new GetCollection(
            uriTemplate: '/factures/verifier-chaine',
            security: "is_granted('PERM', 'facturation.lire')",
            provider: VerifierChaineFactureProvider::class,
        ),
        new Post(
            uriTemplate: '/factures',
            read: false,
            input: false,
            security: "is_granted('PERM', 'facturation.emettre_directe')",
            processor: CreerFactureDirecteProcessor::class,
        ),
        new Patch(
            uriTemplate: '/factures/{id}',
            input: false,
            security: "is_granted('PERM', 'facturation.emettre_directe')",
            processor: ModifierFactureDirecteProcessor::class,
        ),
        new Post(
            uriTemplate: '/factures/depuis-vente',
            read: false,
            input: false,
            security: "is_granted('PERM', 'facturation.emettre_justificative')",
            processor: EmettreFactureJustificativeProcessor::class,
        ),
        new Post(
            uriTemplate: '/factures/{id}/emettre',
            read: true,
            input: false,
            security: "is_granted('PERM', 'facturation.emettre_directe')",
            processor: EmettreFactureDirecteProcessor::class,
        ),
        new Post(
            uriTemplate: '/factures/{id}/reglements',
            read: true,
            input: false,
            security: "is_granted('PERM', 'facturation.lettrer')",
            processor: EnregistrerReglementProcessor::class,
            output: \App\Facturation\Entity\ReglementFacture::class,
            normalizationContext: ['groups' => ['reglement:read']],
        ),
        new Post(
            uriTemplate: '/factures/{id}/avoir',
            read: true,
            input: false,
            security: "is_granted('PERM', 'facturation.avoir')",
            processor: GenererAvoirFactureProcessor::class,
        ),
        new Post(
            uriTemplate: '/factures/{id}/chorus',
            read: true,
            input: false,
            security: "is_granted('PERM', 'facturation.deposer_chorus')",
            processor: DeposerChorusProFactureProcessor::class,
            output: \App\Compta\Entity\FactureB2G::class,
            normalizationContext: ['groups' => ['b2g:read']],
        ),
    ],
    normalizationContext: ['groups' => ['facture:read']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'statut' => 'exact',
    'nature' => 'exact',
    'origine' => 'exact',
    'numero' => 'exact',
    'venteOrigine' => 'exact',
])]
class Facture
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['facture:read'])]
    private Uuid $id;

    /** Numéro définitif — `null` tant que brouillon (RG-FACT-01, aucun numéro consommé en brouillon). */
    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['facture:read'])]
    private ?string $numero = null;

    #[ORM\Column(length: 8, enumType: NatureFacture::class, options: ['default' => 'facture'])]
    #[Groups(['facture:read'])]
    private NatureFacture $nature = NatureFacture::Facture;

    #[ORM\Column(length: 16, enumType: OrigineFacture::class, options: ['default' => 'vente_a_terme'])]
    #[Groups(['facture:read'])]
    private OrigineFacture $origine = OrigineFacture::VenteATerme;

    /** Vente M2 d'origine (déjà scellée/payée) — requise si `origine=ticket_encaisse` (RG-FACT-03/09). */
    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(name: 'vente_origine_id', nullable: true)]
    #[Groups(['facture:read'])]
    private ?Vente $venteOrigine = null;

    /** Facture corrigée par cet avoir (RG-FACT-05) — requise si `nature=avoir`. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['facture:read'])]
    private ?self $factureCorrigee = null;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['facture:read'])]
    private ?ProfilExploitant $profilExploitant = null;

    /** Exercice couvrant `dateEmission` — rempli à l'émission (§4.1 spec, périmètre ⚠ ouvert). */
    #[ORM\ManyToOne(targetEntity: PeriodeComptable::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['facture:read'])]
    private ?PeriodeComptable $periode = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['facture:read'])]
    private ?Etablissement $etablissement = null;

    /** Instantané figé (RG-FACT-08) — propre à cette facture, jamais partagé (§1.3 du plan). */
    #[ORM\OneToOne(targetEntity: DestinataireFacturation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['facture:read'])]
    private ?DestinataireFacturation $destinataire = null;

    #[ORM\Column(length: 24, enumType: StatutFacture::class, options: ['default' => 'brouillon'])]
    #[Groups(['facture:read'])]
    private StatutFacture $statut = StatutFacture::Brouillon;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['facture:read'])]
    private ?\DateTimeImmutable $dateEmission = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['facture:read'])]
    private ?\DateTimeImmutable $dateEcheance = null;

    /** Échéance, pénalités de retard, indemnité forfaitaire de recouvrement (RG-FACT-02). */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['facture:read'])]
    private ?string $conditionsReglement = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['facture:read'])]
    private string $totalHT = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['facture:read'])]
    private string $totalTVA = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['facture:read'])]
    private string $totalTTC = '0.00';

    /**
     * Ventilation de la TVA **par taux** (RG-M6-05 réutilisée : aucun taux moyen), calculée à
     * l'émission.
     *
     * @var list<array{taux: string, baseHT: string, montantTva: string}>|null
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['facture:read'])]
    private ?array $ventilationTva = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['facture:read'])]
    private bool $mentionAcquittee = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['facture:read'])]
    private ?\DateTimeImmutable $acquitteeLe = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['facture:read'])]
    private ?string $acquitteeMoyen = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['facture:read'])]
    private ?string $acquitteeReference = null;

    /** **null si justificative** (RG-FACT-03, le cœur du module) ; renseignée si directe émise. */
    #[ORM\ManyToOne(targetEntity: EcritureComptable::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['facture:read'])]
    private ?EcritureComptable $ecritureGeneree = null;

    /** Ligne « client » (411) de l'écriture de créance, cible du lettrage M6 au règlement (RG-FACT-06). */
    #[ORM\ManyToOne(targetEntity: LigneEcriture::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?LigneEcriture $ligneEcritureClient = null;

    #[ORM\ManyToOne(targetEntity: FactureB2G::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['facture:read'])]
    private ?FactureB2G $factureB2G = null;

    #[ORM\Column(length: 12, enumType: CanalFacture::class, nullable: true)]
    #[Groups(['facture:read'])]
    private ?CanalFacture $canal = null;

    // --- Chaînage NF525 propre au module (plan-facturation.md §0.3) ---
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    #[Groups(['facture:read', 'nf525:read'])]
    private int $numeroSequence = 0;

    #[ORM\Column(length: 128, options: ['default' => ''])]
    #[Groups(['facture:read', 'nf525:read'])]
    private string $empreinte = '';

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['facture:read', 'nf525:read'])]
    private ?string $empreintePrecedente = null;

    #[ORM\Column(length: 512, options: ['default' => ''])]
    #[Groups(['facture:read', 'nf525:read'])]
    private string $signature = '';

    /** @var Collection<int, LigneFacture> */
    #[ORM\OneToMany(targetEntity: LigneFacture::class, mappedBy: 'facture', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['facture:read'])]
    private Collection $lignes;

    /** @var Collection<int, ReglementFacture> */
    #[ORM\OneToMany(targetEntity: ReglementFacture::class, mappedBy: 'facture', cascade: ['persist', 'remove'])]
    #[Groups(['facture:read'])]
    private Collection $reglements;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['facture:read'])]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['facture:read'])]
    private ?Utilisateur $creePar = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->creeLe = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
        $this->reglements = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getNature(): NatureFacture
    {
        return $this->nature;
    }

    public function setNature(NatureFacture $nature): self
    {
        $this->nature = $nature;

        return $this;
    }

    public function getOrigine(): OrigineFacture
    {
        return $this->origine;
    }

    public function setOrigine(OrigineFacture $origine): self
    {
        $this->origine = $origine;

        return $this;
    }

    public function getVenteOrigine(): ?Vente
    {
        return $this->venteOrigine;
    }

    public function setVenteOrigine(?Vente $venteOrigine): self
    {
        $this->venteOrigine = $venteOrigine;

        return $this;
    }

    public function getFactureCorrigee(): ?self
    {
        return $this->factureCorrigee;
    }

    public function setFactureCorrigee(?self $factureCorrigee): self
    {
        $this->factureCorrigee = $factureCorrigee;

        return $this;
    }

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    public function getPeriode(): ?PeriodeComptable
    {
        return $this->periode;
    }

    public function setPeriode(?PeriodeComptable $periode): self
    {
        $this->periode = $periode;

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

    public function getDestinataire(): ?DestinataireFacturation
    {
        return $this->destinataire;
    }

    public function setDestinataire(?DestinataireFacturation $destinataire): self
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getStatut(): StatutFacture
    {
        return $this->statut;
    }

    public function setStatut(StatutFacture $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateEmission(): ?\DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function setDateEmission(?\DateTimeImmutable $dateEmission): self
    {
        $this->dateEmission = $dateEmission;

        return $this;
    }

    public function getDateEcheance(): ?\DateTimeImmutable
    {
        return $this->dateEcheance;
    }

    public function setDateEcheance(?\DateTimeImmutable $dateEcheance): self
    {
        $this->dateEcheance = $dateEcheance;

        return $this;
    }

    public function getConditionsReglement(): ?string
    {
        return $this->conditionsReglement;
    }

    public function setConditionsReglement(?string $conditionsReglement): self
    {
        $this->conditionsReglement = $conditionsReglement;

        return $this;
    }

    public function getTotalHT(): string
    {
        return $this->totalHT;
    }

    public function setTotalHT(string $totalHT): self
    {
        $this->totalHT = $totalHT;

        return $this;
    }

    public function getTotalTVA(): string
    {
        return $this->totalTVA;
    }

    public function setTotalTVA(string $totalTVA): self
    {
        $this->totalTVA = $totalTVA;

        return $this;
    }

    public function getTotalTTC(): string
    {
        return $this->totalTTC;
    }

    public function setTotalTTC(string $totalTTC): self
    {
        $this->totalTTC = $totalTTC;

        return $this;
    }

    /** @return list<array{taux: string, baseHT: string, montantTva: string}>|null */
    public function getVentilationTva(): ?array
    {
        return $this->ventilationTva;
    }

    /** @param list<array{taux: string, baseHT: string, montantTva: string}>|null $ventilationTva */
    public function setVentilationTva(?array $ventilationTva): self
    {
        $this->ventilationTva = $ventilationTva;

        return $this;
    }

    public function isMentionAcquittee(): bool
    {
        return $this->mentionAcquittee;
    }

    public function setMentionAcquittee(bool $mentionAcquittee): self
    {
        $this->mentionAcquittee = $mentionAcquittee;

        return $this;
    }

    public function getAcquitteeLe(): ?\DateTimeImmutable
    {
        return $this->acquitteeLe;
    }

    public function setAcquitteeLe(?\DateTimeImmutable $acquitteeLe): self
    {
        $this->acquitteeLe = $acquitteeLe;

        return $this;
    }

    public function getAcquitteeMoyen(): ?string
    {
        return $this->acquitteeMoyen;
    }

    public function setAcquitteeMoyen(?string $acquitteeMoyen): self
    {
        $this->acquitteeMoyen = $acquitteeMoyen;

        return $this;
    }

    public function getAcquitteeReference(): ?string
    {
        return $this->acquitteeReference;
    }

    public function setAcquitteeReference(?string $acquitteeReference): self
    {
        $this->acquitteeReference = $acquitteeReference;

        return $this;
    }

    public function getEcritureGeneree(): ?EcritureComptable
    {
        return $this->ecritureGeneree;
    }

    public function setEcritureGeneree(?EcritureComptable $ecritureGeneree): self
    {
        $this->ecritureGeneree = $ecritureGeneree;

        return $this;
    }

    public function getLigneEcritureClient(): ?LigneEcriture
    {
        return $this->ligneEcritureClient;
    }

    public function setLigneEcritureClient(?LigneEcriture $ligneEcritureClient): self
    {
        $this->ligneEcritureClient = $ligneEcritureClient;

        return $this;
    }

    public function getFactureB2G(): ?FactureB2G
    {
        return $this->factureB2G;
    }

    public function setFactureB2G(?FactureB2G $factureB2G): self
    {
        $this->factureB2G = $factureB2G;

        return $this;
    }

    public function getCanal(): ?CanalFacture
    {
        return $this->canal;
    }

    public function setCanal(?CanalFacture $canal): self
    {
        $this->canal = $canal;

        return $this;
    }

    public function getNumeroSequence(): int
    {
        return $this->numeroSequence;
    }

    public function setNumeroSequence(int $numeroSequence): self
    {
        $this->numeroSequence = $numeroSequence;

        return $this;
    }

    public function getEmpreinte(): string
    {
        return $this->empreinte;
    }

    public function setEmpreinte(string $empreinte): self
    {
        $this->empreinte = $empreinte;

        return $this;
    }

    public function getEmpreintePrecedente(): ?string
    {
        return $this->empreintePrecedente;
    }

    public function setEmpreintePrecedente(?string $empreintePrecedente): self
    {
        $this->empreintePrecedente = $empreintePrecedente;

        return $this;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function setSignature(string $signature): self
    {
        $this->signature = $signature;

        return $this;
    }

    /** @return Collection<int, LigneFacture> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneFacture $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setFacture($this);
        }

        return $this;
    }

    public function removeLigne(LigneFacture $ligne): self
    {
        $this->lignes->removeElement($ligne);

        return $this;
    }

    /** @return Collection<int, ReglementFacture> */
    public function getReglements(): Collection
    {
        return $this->reglements;
    }

    public function addReglement(ReglementFacture $reglement): self
    {
        if (!$this->reglements->contains($reglement)) {
            $this->reglements->add($reglement);
            $reglement->setFacture($this);
        }

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
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

    // --- Règles métier lisibles depuis les gardes, handlers et tests ---

    public function estBrouillon(): bool
    {
        return $this->statut->estBrouillon();
    }

    /** Scellée = plus jamais modifiable dans son contenu (même critère que `EcritureComptable::estScellee()`). */
    public function estScellee(): bool
    {
        return $this->empreinte !== '';
    }

    /** Cumul des règlements enregistrés (decimal), RG-FACT-06. */
    #[Groups(['facture:read'])]
    public function getMontantRegle(): string
    {
        $centimes = 0;
        foreach ($this->reglements as $reglement) {
            $centimes += self::centimes($reglement->getMontant());
        }

        return self::decimal($centimes);
    }

    /** Solde restant dû = TTC − règlements (RG-FACT-06). */
    #[Groups(['facture:read'])]
    public function getSoldeDu(): string
    {
        return self::decimal(self::centimes($this->totalTTC) - self::centimes($this->getMontantRegle()));
    }

    /** Recalcule les totaux et la ventilation TVA à partir des lignes (aucun taux moyen, RG-M6-05). */
    public function recalculerTotaux(): self
    {
        $htCentimes = 0;
        $tvaCentimes = 0;
        /** @var array<string, array{taux: string, baseHT: int, montantTva: int}> $parTaux */
        $parTaux = [];

        foreach ($this->lignes as $ligne) {
            $ligne->recalculer();
            $ht = self::centimes($ligne->getMontantHT());
            $tva = self::centimes($ligne->getMontantTva());
            $htCentimes += $ht;
            $tvaCentimes += $tva;

            $cle = $ligne->getTauxTvaValeur();
            if (!isset($parTaux[$cle])) {
                $parTaux[$cle] = ['taux' => $cle, 'baseHT' => 0, 'montantTva' => 0];
            }
            $parTaux[$cle]['baseHT'] += $ht;
            $parTaux[$cle]['montantTva'] += $tva;
        }

        ksort($parTaux);
        $ventilation = [];
        foreach ($parTaux as $entree) {
            $ventilation[] = [
                'taux' => $entree['taux'],
                'baseHT' => self::decimal($entree['baseHT']),
                'montantTva' => self::decimal($entree['montantTva']),
            ];
        }

        $this->totalHT = self::decimal($htCentimes);
        $this->totalTVA = self::decimal($tvaCentimes);
        $this->totalTTC = self::decimal($htCentimes + $tvaCentimes);
        $this->ventilationTva = $ventilation;

        return $this;
    }

    /** Vrai si l'utilisateur connecté (espace client M3) est le destinataire — permission `_soi` (CA-10). */
    public function estLieA(mixed $user): bool
    {
        if (!$user instanceof Utilisateur) {
            return false;
        }
        $lie = $user->getClientLie();
        $ref = $this->destinataire?->getClientRef();

        return $lie !== null && $ref !== null && (string) $lie === (string) $ref;
    }

    private static function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private static function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }
}
