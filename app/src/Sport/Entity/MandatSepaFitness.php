<?php

declare(strict_types=1);

namespace App\Sport\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Crm\Entity\Client;
use App\Sport\Enum\StatutMandatSepaFitness;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Mandat SEPA fitness (US-SPORT-10, cahier §4). ⚠ IBAN — garde de sécurité applicative (spec §4,
 * plan §4) : `ibanToken` n'est **jamais** porté par un groupe de sérialisation (aucun `#[Groups]`
 * dessus), donc jamais exposé en API quel que soit le contexte demandé. Seul `iban4Derniers` est
 * lisible. L'IBAN en clair ne transite qu'en entrée d'un processor, tokenisé avant persistance
 * (`TokenisationIbanInterface`) — jamais mappé Doctrine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sport_mandat_sepa_fitness')]
#[ORM\UniqueConstraint(name: 'uniq_mandat_rum', columns: ['rum'])]
#[ORM\UniqueConstraint(name: 'uniq_mandat_abonnement', columns: ['abonnement_rattache_id'])]
#[ApiResource(
    shortName: 'MandatSepaFitness',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sport.lire') or is_granted('PERM', 'sport.gerer_abonnement')"),
        new Get(security: "is_granted('PERM', 'sport.lire') or is_granted('PERM', 'sport.gerer_abonnement')"),
    ],
    normalizationContext: ['groups' => ['mandat:read']],
)]
class MandatSepaFitness
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mandat:read', 'abonnement:read'])]
    private Uuid $id;

    #[ORM\Column(length: 35, unique: true)]
    #[Groups(['mandat:read', 'abonnement:read'])]
    private string $rum = '';

    /**
     * Jeton HMAC non réversible trivialement (`TokenisationIbanInterface`). Volontairement **sans**
     * `#[Groups]` : ne doit jamais apparaître dans une réponse API, quel que soit le contexte demandé.
     */
    #[ORM\Column(length: 128)]
    private string $ibanToken = '';

    #[ORM\Column(length: 4)]
    #[Groups(['mandat:read', 'abonnement:read'])]
    private string $iban4Derniers = '';

    #[ORM\Column(length: 180)]
    #[Groups(['mandat:read'])]
    private string $titulaire = '';

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['mandat:read'])]
    private \DateTimeImmutable $dateSignature;

    #[ORM\Column(length: 10, enumType: StatutMandatSepaFitness::class, options: ['default' => 'actif'])]
    #[Groups(['mandat:read', 'abonnement:read'])]
    private StatutMandatSepaFitness $statut = StatutMandatSepaFitness::Actif;

    // Nullable au niveau schéma (nécessaire pour casser le cycle d'insertion avec
    // `AbonnementFitness.mandatSepa`, également requis en 1:1) : toujours renseigné applicativement
    // juste après la création des deux entités (`SouscriptionAbonnementHandler`/`ReengagementHandler`).
    #[ORM\OneToOne(targetEntity: AbonnementFitness::class)]
    #[ORM\JoinColumn(name: 'abonnement_rattache_id', nullable: true)]
    #[Groups(['mandat:read'])]
    private ?AbonnementFitness $abonnementRattache = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mandat:read'])]
    private ?Client $payeur = null;

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

    public function getTitulaire(): string
    {
        return $this->titulaire;
    }

    public function setTitulaire(string $titulaire): self
    {
        $this->titulaire = $titulaire;

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

    public function getStatut(): StatutMandatSepaFitness
    {
        return $this->statut;
    }

    public function setStatut(StatutMandatSepaFitness $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getAbonnementRattache(): ?AbonnementFitness
    {
        return $this->abonnementRattache;
    }

    public function setAbonnementRattache(?AbonnementFitness $abonnementRattache): self
    {
        $this->abonnementRattache = $abonnementRattache;

        return $this;
    }

    public function getPayeur(): ?Client
    {
        return $this->payeur;
    }

    public function setPayeur(?Client $payeur): self
    {
        $this->payeur = $payeur;

        return $this;
    }
}
