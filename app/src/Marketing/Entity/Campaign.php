<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Marketing\Enum\CampaignStatus;
use App\Marketing\State\CampaignResultProvider;
use App\Marketing\State\MarketingEstablishmentStampProcessor;
use App\Marketing\State\SendCampaignProcessor;
use App\Organisation\Entity\Etablissement;
use App\Platform\Notification\NotificationChannel;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * UNE CAMPAGNE — un message, une audience, et la trace de ce qui s'est passé.
 *
 * ── UN CANAL PAR CAMPAGNE, ET C'EST UN CHOIX ────────────────────────────────────────────────────
 *
 * Le cahier parle de campagnes « multicanal ». Cela veut dire que l'outil sait faire courriel, SMS
 * et push — pas qu'un même message part sur les trois. Un texte écrit pour un courriel fait un
 * mauvais SMS : cent soixante caractères, pas de mise en forme, et un coût à l'unité. Prétendre
 * qu'un message unique convient aux deux produit des SMS tronqués que personne ne relit.
 *
 * Une campagne sur deux canaux, c'est donc deux campagnes — et deux messages écrits pour ce qu'ils
 * sont.
 *
 * ── LE GROUPE TÉMOIN N'EST PAS UNE OPTION TECHNIQUE ─────────────────────────────────────────────
 *
 * Une part de l'audience est **délibérément non contactée**. Sans elle, on mesure combien de gens
 * sont revenus ; avec elle, on mesure combien sont revenus **grâce à** la campagne. La différence
 * entre les deux groupes est le seul chiffre honnête que produit ce module, et c'est celui qu'aucun
 * outil d'emailing généraliste ne peut donner — parce qu'il ne tient pas la vente.
 *
 * Dix pour cent par défaut. C'est dix pour cent de chiffre d'affaires potentiel sacrifié pour savoir
 * si la campagne sert à quelque chose ; réglable, et désactivable en mettant zéro. L'écran dit ce
 * que ça coûte plutôt que de le décider en silence.
 *
 * ── LA FENÊTRE D'ATTRIBUTION EST PORTÉE PAR LA CAMPAGNE ─────────────────────────────────────────
 *
 * Trente jours conviennent à une piscine ; un musée a des cycles plus longs. La porter sur la
 * campagne plutôt que sur l'établissement permet de mesurer une relance de fin de saison autrement
 * qu'une offre de rentrée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_campaign')]
#[ORM\Index(name: 'idx_marketing_campaign_statut', columns: ['establishment_id', 'status'])]
#[ApiResource(
    shortName: 'Campaign',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'campagne.lire')"),
        new Get(security: "is_granted('PERM', 'campagne.lire')"),
        new Post(
            security: "is_granted('PERM', 'campagne.gerer')",
            denormalizationContext: ['groups' => ['campaign:write']],
            processor: MarketingEstablishmentStampProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'campagne.gerer')",
            denormalizationContext: ['groups' => ['campaign:write']],
        ),

        // L'ENVOI — le seul endroit où le consentement, le plafond et le groupe témoin s'appliquent.
        new Post(
            uriTemplate: '/marketing/campagnes/{id}/envoyer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'campagne.gerer')",
            processor: SendCampaignProcessor::class,
        ),

        // LE RÉSULTAT — contactés, exclus par motif, témoins. Calculé, jamais recopié.
        new Get(
            uriTemplate: '/marketing/campagnes/{id}/resultat',
            security: "is_granted('PERM', 'campagne.lire')",
            provider: CampaignResultProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['campaign:read']],
)]
class Campaign
{
    /** Dix pour cent : assez pour comparer, assez peu pour ne pas sacrifier la campagne. */
    public const TEMOIN_PAR_DEFAUT = 10;

    /** Trente jours : le cycle d'une piscine. Un musée allongera. */
    public const FENETRE_PAR_DEFAUT = 30;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['campaign:read'])]
    private Uuid $id;

    /** Posé par le serveur, jamais par le corps de la requête (D41). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['campaign:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['campaign:read', 'campaign:write'])]
    private string $label = '';

    #[ORM\ManyToOne(targetEntity: Segment::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['campaign:read', 'campaign:write'])]
    private ?Segment $segment = null;

    #[ORM\Column(length: 12, enumType: NotificationChannel::class)]
    #[Groups(['campaign:read', 'campaign:write'])]
    private NotificationChannel $channel = NotificationChannel::Email;

    #[ORM\Column(length: 200)]
    #[Groups(['campaign:read', 'campaign:write'])]
    private string $subject = '';

    /** Le corps, avec ses variables nommées : `{{prenom}}`, jamais de concaténation. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Groups(['campaign:read', 'campaign:write'])]
    private string $body = '';

    #[ORM\Column(length: 12, enumType: CampaignStatus::class)]
    #[Groups(['campaign:read'])]
    private CampaignStatus $status = CampaignStatus::Brouillon;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['campaign:read', 'campaign:write'])]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['campaign:read'])]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(options: ['default' => self::TEMOIN_PAR_DEFAUT])]
    #[Assert\Range(min: 0, max: 50)]
    #[Groups(['campaign:read', 'campaign:write'])]
    private int $controlGroupPercent = self::TEMOIN_PAR_DEFAUT;

    #[ORM\Column(options: ['default' => self::FENETRE_PAR_DEFAUT])]
    #[Assert\Range(min: 1, max: 365)]
    #[Groups(['campaign:read', 'campaign:write'])]
    private int $attributionWindowDays = self::FENETRE_PAR_DEFAUT;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): ?Etablissement
    {
        return $this->establishment;
    }

    public function setEstablishment(?Etablissement $establishment): self
    {
        $this->establishment = $establishment;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getSegment(): ?Segment
    {
        return $this->segment;
    }

    public function setSegment(?Segment $segment): self
    {
        $this->segment = $segment;

        return $this;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function setChannel(NotificationChannel $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getStatus(): CampaignStatus
    {
        return $this->status;
    }

    public function setStatus(CampaignStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getControlGroupPercent(): int
    {
        return $this->controlGroupPercent;
    }

    public function setControlGroupPercent(int $controlGroupPercent): self
    {
        $this->controlGroupPercent = $controlGroupPercent;

        return $this;
    }

    public function getAttributionWindowDays(): int
    {
        return $this->attributionWindowDays;
    }

    public function setAttributionWindowDays(int $attributionWindowDays): self
    {
        $this->attributionWindowDays = $attributionWindowDays;

        return $this;
    }

    /**
     * UNE VARIABLE QU'ON NE SAIT PAS REMPLIR EST REFUSÉE À L'ÉCRITURE.
     *
     * `{{solde_carte}}` dans un message est une promesse ; si le serveur ne sait pas la tenir, elle
     * exclura chaque destinataire au moment de l'envoi — et l'exploitant découvrira son erreur sur
     * un rapport disant « 1 240 exclus, information manquante ».
     *
     * Autant le lui dire pendant qu'il écrit.
     */
    #[Assert\Callback]
    public function validerVariables(ExecutionContextInterface $context): void
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $this->subject . ' ' . $this->body, $trouvees);

        foreach (array_unique($trouvees[1]) as $variable) {
            if (!\in_array(strtolower($variable), MessageVariables::CONNUES, true)) {
                $context->buildViolation('Variable inconnue : « {{ nom }} ». Elle exclurait chaque destinataire au lieu de se remplir.')
                    ->setParameter('{{ nom }}', (string) $variable)
                    ->atPath('body')
                    ->addViolation();
            }
        }
    }

    /**
     * Un canal sans transport n'est pas un canal silencieux.
     *
     * Le courrier postal n'a pas d'adaptateur, même journalisé : le proposer laisserait croire qu'on
     * peut lancer un publipostage papier depuis cet écran.
     */
    #[Assert\Callback]
    public function validerCanal(ExecutionContextInterface $context): void
    {
        if ($this->channel === NotificationChannel::Courrier) {
            $context->buildViolation('Le courrier postal n’est pas un canal de campagne : rien ne l’imprime ni ne l’affranchit.')
                ->atPath('channel')
                ->addViolation();
        }
    }
}
