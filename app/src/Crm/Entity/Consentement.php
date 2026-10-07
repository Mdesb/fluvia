<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Consentement RGPD par canal (RG-M4-07). **Append-only** : chaque changement (accord, révocation,
 * renouvellement) insère une nouvelle ligne (garde `InalterabiliteCrmListener`) ; l'état courant d'un
 * canal = la ligne la plus récente (`App\Crm\Service\ConsentementResolver`). La création passe par
 * la sous-ressource `POST /clients/{id}/consentements` (déclarée sur `App\Crm\Entity\Client`, même
 * raison qu'au-dessus de `PorteMonnaieVirtuel` : éviter un `{id}` d'URI ambigu).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_consentement')]
#[ORM\Index(name: 'idx_consentement_client_canal_date', columns: ['client_id', 'canal', 'date_recueil'])]
#[ApiResource(
    shortName: 'Consentement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.lire')"),
        new Get(security: "is_granted('PERM', 'crm.lire')"),
    ],
    normalizationContext: ['groups' => ['consentement:read']],
    denormalizationContext: ['groups' => ['consentement:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['client' => 'exact', 'canal' => 'exact', 'etat' => 'exact'])]
class Consentement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['consentement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['consentement:read', 'consentement:write'])]
    private ?Client $client = null;

    #[ORM\Column(length: 12, enumType: CanalConsentement::class)]
    #[Groups(['consentement:read', 'consentement:write'])]
    private CanalConsentement $canal;

    #[ORM\Column(length: 12, enumType: EtatConsentement::class)]
    #[Groups(['consentement:read', 'consentement:write'])]
    private EtatConsentement $etat;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['consentement:read'])]
    private \DateTimeImmutable $dateRecueil;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['consentement:read', 'consentement:write'])]
    private ?\DateTimeImmutable $dateExpiration = null;

    #[ORM\Column(length: 64)]
    #[Groups(['consentement:read', 'consentement:write'])]
    private string $source = '';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['consentement:read', 'consentement:write'])]
    private bool $recueilliParRepresentant = false;

    /**
     * La version du texte auquel la personne a répondu (#101). Un accord se prouve par ce qu'il
     * disait : « recevoir les nouveautés de X par e-mail » n'engage pas à la même chose qu'un texte
     * modifié depuis. `null` pour les lignes antérieures, qui n'en gardaient pas trace.
     */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['consentement:read'])]
    private ?string $textVersion = null;

    /** Motif d'une ligne `invalide` — posé par une reprise, jamais par l'API (#101). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['consentement:read'])]
    private ?string $invalidationReason = null;

    /** Référence du lot qui a invalidé — ce qui permet de défaire exactement cette reprise-là. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['consentement:read'])]
    private ?string $invalidationBatch = null;

    public function __construct(CanalConsentement $canal = CanalConsentement::Email, EtatConsentement $etat = EtatConsentement::Refuse)
    {
        $this->id = Uuid::v4();
        $this->canal = $canal;
        $this->etat = $etat;
        $this->dateRecueil = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getCanal(): CanalConsentement
    {
        return $this->canal;
    }

    public function setCanal(CanalConsentement $canal): self
    {
        $this->canal = $canal;

        return $this;
    }

    public function getEtat(): EtatConsentement
    {
        return $this->etat;
    }

    public function setEtat(EtatConsentement $etat): self
    {
        $this->etat = $etat;

        return $this;
    }

    public function getDateRecueil(): \DateTimeImmutable
    {
        return $this->dateRecueil;
    }

    public function setDateRecueil(\DateTimeImmutable $dateRecueil): self
    {
        $this->dateRecueil = $dateRecueil;

        return $this;
    }

    public function getDateExpiration(): ?\DateTimeImmutable
    {
        return $this->dateExpiration;
    }

    public function setDateExpiration(?\DateTimeImmutable $dateExpiration): self
    {
        $this->dateExpiration = $dateExpiration;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function isRecueilliParRepresentant(): bool
    {
        return $this->recueilliParRepresentant;
    }

    public function setRecueilliParRepresentant(bool $recueilliParRepresentant): self
    {
        $this->recueilliParRepresentant = $recueilliParRepresentant;

        return $this;
    }

    public function getTextVersion(): ?string
    {
        return $this->textVersion;
    }

    public function setTextVersion(?string $textVersion): self
    {
        $this->textVersion = $textVersion;

        return $this;
    }

    public function getInvalidationReason(): ?string
    {
        return $this->invalidationReason;
    }

    public function getInvalidationBatch(): ?string
    {
        return $this->invalidationBatch;
    }

    /** Valide et non expiré (RG-M4-07). */
    public function estExploitable(\DateTimeImmutable $reference = new \DateTimeImmutable()): bool
    {
        if ($this->etat !== EtatConsentement::Accorde) {
            return false;
        }

        return $this->dateExpiration === null || $this->dateExpiration >= $reference;
    }
}
