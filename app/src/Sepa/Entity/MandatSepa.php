<?php

declare(strict_types=1);

namespace App\Sepa\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Enum\SeqTpSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\State\CreerMandatSepaProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Mandat SEPA générique (plan §2, remplace `App\Sport\Entity\MandatSepaFitness` — module partagé
 * réutilisable par toute verticale : Sport, Piscine…). Rattaché à un `Client` (M4) et à un
 * établissement (cloisonnement multi-entités, `App\Sepa\Doctrine\PerimetreSepaExtension`).
 *
 * ⚠ IBAN — garde de sécurité applicative (spec §4, plan §2) : `ibanToken` et `ibanChiffre` ne sont
 * **jamais** portés par un groupe de sérialisation (aucun `#[Groups]` dessus), donc jamais exposés en
 * API quel que soit le contexte demandé. Seul `iban4Derniers` est lisible. L'IBAN en clair ne transite
 * qu'en entrée d'un processor : tokenisé (`TokenisationIbanInterface`, non réversible, affichage/
 * recherche) **et** chiffré (`ChiffreurIbanInterface`, réversible, libsodium) avant persistance —
 * jamais mappé Doctrine en clair. Le déchiffrement (`ibanChiffre`) n'a lieu que côté serveur, au
 * moment strict où `Pain008Generator` construit la remise pain.008.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sepa_mandat')]
#[ORM\UniqueConstraint(name: 'uniq_sepa_mandat_rum', columns: ['rum'])]
#[ApiResource(
    shortName: 'MandatSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/sepa/mandats',
            read: false,
            input: false,
            security: "is_granted('PERM', 'sepa.gerer')",
            processor: CreerMandatSepaProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['mandat_sepa:read']],
)]
class MandatSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mandat_sepa:read', 'abonnement:read'])]
    private Uuid $id;

    #[ORM\Column(length: 35, unique: true)]
    #[Groups(['mandat_sepa:read', 'abonnement:read'])]
    private string $rum = '';

    /**
     * Jeton HMAC non réversible trivialement (`TokenisationIbanInterface`). Volontairement **sans**
     * `#[Groups]` : ne doit jamais apparaître dans une réponse API, quel que soit le contexte demandé.
     */
    #[ORM\Column(length: 128)]
    private string $ibanToken = '';

    #[ORM\Column(length: 4)]
    #[Groups(['mandat_sepa:read', 'abonnement:read'])]
    private string $iban4Derniers = '';

    /**
     * Coffre IBAN réversible (`ChiffreurIbanInterface`, libsodium) — nonce+cipher base64. Volontairement
     * **sans** `#[Groups]`, comme `ibanToken` : ne doit jamais apparaître dans une réponse API. Ne
     * déchiffré que côté serveur par `Pain008Generator` au moment strict de générer la remise.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ibanChiffre = null;

    #[ORM\Column(length: 11)]
    #[Groups(['mandat_sepa:read'])]
    private string $bicDebiteur = '';

    #[ORM\Column(length: 180)]
    #[Groups(['mandat_sepa:read'])]
    private string $debiteurNom = '';

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['mandat_sepa:read'])]
    private \DateTimeImmutable $dateSignature;

    #[ORM\Column(length: 10, enumType: StatutMandatSepa::class, options: ['default' => 'actif'])]
    #[Groups(['mandat_sepa:read', 'abonnement:read'])]
    private StatutMandatSepa $statut = StatutMandatSepa::Actif;

    /** Dernière `SeqTp` utilisée pour ce mandat (informatif, mise à jour par `GenerationRemiseHandler`). */
    #[ORM\Column(length: 4, enumType: SeqTpSepa::class, nullable: true)]
    #[Groups(['mandat_sepa:read'])]
    private ?SeqTpSepa $sequenceCourante = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['mandat_sepa:read'])]
    private int $nbCollectesReussies = 0;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mandat_sepa:read'])]
    private ?Client $client = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mandat_sepa:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateSignature = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRum(): string
    {
        return $this->rum;
    }

    public function setRum(string $rum): self
    {
        $this->rum = $rum;

        return $this;
    }

    public function getIbanToken(): string
    {
        return $this->ibanToken;
    }

    public function setIbanToken(string $ibanToken): self
    {
        $this->ibanToken = $ibanToken;

        return $this;
    }

    public function getIban4Derniers(): string
    {
        return $this->iban4Derniers;
    }

    public function setIban4Derniers(string $iban4Derniers): self
    {
        $this->iban4Derniers = $iban4Derniers;

        return $this;
    }

    public function getIbanChiffre(): ?string
    {
        return $this->ibanChiffre;
    }

    public function setIbanChiffre(?string $ibanChiffre): self
    {
        $this->ibanChiffre = $ibanChiffre;

        return $this;
    }

    public function getBicDebiteur(): string
    {
        return $this->bicDebiteur;
    }

    public function setBicDebiteur(string $bicDebiteur): self
    {
        $this->bicDebiteur = $bicDebiteur;

        return $this;
    }

    public function getDebiteurNom(): string
    {
        return $this->debiteurNom;
    }

    public function setDebiteurNom(string $debiteurNom): self
    {
        $this->debiteurNom = $debiteurNom;

        return $this;
    }

    public function getDateSignature(): \DateTimeImmutable
    {
        return $this->dateSignature;
    }

    public function setDateSignature(\DateTimeImmutable $dateSignature): self
    {
        $this->dateSignature = $dateSignature;

        return $this;
    }

    public function getStatut(): StatutMandatSepa
    {
        return $this->statut;
    }

    public function setStatut(StatutMandatSepa $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getSequenceCourante(): ?SeqTpSepa
    {
        return $this->sequenceCourante;
    }

    public function setSequenceCourante(?SeqTpSepa $sequenceCourante): self
    {
        $this->sequenceCourante = $sequenceCourante;

        return $this;
    }

    public function getNbCollectesReussies(): int
    {
        return $this->nbCollectesReussies;
    }

    public function setNbCollectesReussies(int $nbCollectesReussies): self
    {
        $this->nbCollectesReussies = $nbCollectesReussies;

        return $this;
    }

    public function incrementerCollectesReussies(): self
    {
        ++$this->nbCollectesReussies;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

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
