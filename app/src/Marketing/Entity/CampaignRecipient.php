<?php

declare(strict_types=1);

namespace App\Marketing\Entity;

use App\Crm\Entity\Client;
use App\Marketing\Enum\ExclusionReason;
use App\Marketing\Enum\RecipientOutcome;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * LA LISTE FIGÉE — la seule chose de ce module qui se stocke plutôt que de se calculer.
 *
 * Un segment se recalcule à chaque lecture ; cette liste-ci, jamais. Trois raisons, et chacune
 * suffirait :
 *
 * **1. L'attribution.** Rattacher les ventes suivantes suppose de savoir QUI a été contacté ce
 * jour-là. Recalculer l'audience au moment de mesurer donnerait un chiffre flatteur et faux : on
 * compterait les ventes de gens qui n'ont jamais reçu le message (RG-CMP-12).
 *
 * **2. Le journal RGPD.** Qui, quand, sur quel canal, sur quelle base légale — c'est une obligation,
 * et c'est aussi le contrôle citoyen du module. Un mécanisme qu'on ne peut pas relire finit par ne
 * plus être surveillé.
 *
 * **3. Le plafond de sollicitation.** Savoir qu'on a déjà écrit à quelqu'un la semaine dernière
 * suppose d'en avoir gardé la trace.
 *
 * ── ON NE RÉÉCRIT PAS L'HISTOIRE ────────────────────────────────────────────────────────────────
 *
 * Un client qui retire son consentement après l'envoi **reste** dans ce journal : il a bien été
 * contacté, à un moment où c'était légitime. Il ne le sera plus ensuite. Effacer la ligne
 * effacerait la preuve que l'envoi était régulier.
 *
 * De même, une campagne arrêtée en cours d'envoi garde ses déjà-contactés — et leurs ventes lui
 * restent rattachées.
 *
 * ── LE CLIENT EST UNE RÉFÉRENCE LIBRE, PAS UNE RELATION ─────────────────────────────────────────
 *
 * `Client` appartient au CRM ; une relation Doctrine créerait une dépendance de mapping entre deux
 * modules qui doivent vivre séparément (D2). L'identifiant est donc porté nu — avec le prix qui va
 * avec : toute comparaison sur cette colonne doit passer le type `uuid` explicitement, faute de quoi
 * elle ne compte rien **et ne lève pas** (D58).
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketing_campaign_recipient')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_recipient', columns: ['campaign_id', 'customer_ref'])]
#[ORM\Index(name: 'idx_marketing_recipient_pression', columns: ['customer_ref', 'channel', 'notified_at'])]
class CampaignRecipient
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Campaign $campaign = null;

    /** Référence libre vers `Crm\Entity\Client` — pas de relation, D2. */
    #[ORM\Column(name: 'customer_ref', type: UuidType::NAME)]
    private Uuid $customerRef;

    /** Recopié depuis la campagne : le canal d'un envoi ne change pas si la campagne est modifiée. */
    #[ORM\Column(length: 12)]
    private string $channel = 'email';

    #[ORM\Column(length: 12, enumType: RecipientOutcome::class)]
    private RecipientOutcome $outcome = RecipientOutcome::Journalise;

    #[ORM\Column(length: 30, enumType: ExclusionReason::class, nullable: true)]
    private ?ExclusionReason $exclusionReason = null;

    /**
     * L'instant où la décision a été prise — y compris pour un exclu.
     *
     * Daté même quand rien n'est parti : le plafond de sollicitation compte les CONTACTS, et un
     * exclu n'en est pas un. C'est le champ `outcome` qui tranche, pas la présence d'une date.
     */
    #[ORM\Column(name: 'notified_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $notifiedAt;

    public function __construct(Campaign $campaign, Client $client, RecipientOutcome $outcome)
    {
        $this->id = Uuid::v4();
        $this->campaign = $campaign;
        $this->customerRef = $client->getId();
        $this->channel = $campaign->getChannel()->value;
        $this->outcome = $outcome;
        $this->notifiedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function getCustomerRef(): Uuid
    {
        return $this->customerRef;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getOutcome(): RecipientOutcome
    {
        return $this->outcome;
    }

    public function getExclusionReason(): ?ExclusionReason
    {
        return $this->exclusionReason;
    }

    public function setExclusionReason(?ExclusionReason $exclusionReason): self
    {
        $this->exclusionReason = $exclusionReason;

        return $this;
    }

    public function getNotifiedAt(): \DateTimeImmutable
    {
        return $this->notifiedAt;
    }
}
