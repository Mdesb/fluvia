<?php

declare(strict_types=1);

namespace App\PublicApi\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * L'abonnement d'une application partenaire aux événements sortants (spec API partenaire v1, §3.3).
 *
 * ⚠ **L'URL ET LE SECRET SONT CHIFFRÉS AU REPOS** (`PartnerWebhookCipher`, clé `PARTNER_WEBHOOK_KEY`) :
 * le secret signe les livraisons — qui le lit en base peut forger un événement que le partenaire
 * croira venu de Fluvia ; l'URL peut porter un jeton. Seul l'hôte est gardé en clair, pour l'écran.
 *
 * ⚠ **UN ABONNEMENT NE DONNE ACCÈS À RIEN PAR LUI-MÊME.** Seuls les établissements qui ont accordé
 * `events:subscribe` à l'application reçoivent ses événements, et l'accord est revérifié à chaque
 * tentative de livraison.
 *
 * Un seul abonnement par application : l'éditeur le reconfigure, il n'en empile pas.
 */
#[ORM\Entity]
#[ORM\Table(name: 'public_api_webhook_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_public_api_webhook_application', columns: ['application_id'])]
class PartnerWebhookSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PartnerApplication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?PartnerApplication $application = null;

    #[ORM\Column(type: 'text')]
    private string $encryptedUrl = '';

    /** L'hôte seul, en clair : ce que l'écran montre, sans jamais réafficher l'adresse complète. */
    #[ORM\Column(length: 255)]
    private string $urlHost = '';

    /** @var list<string> des noms de `PartnerEventCatalog::EVENTS` */
    #[ORM\Column(type: 'json')]
    private array $events = [];

    #[ORM\Column(type: 'text')]
    private string $encryptedSecret = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $secretRotatedAt;

    public function __construct(PartnerApplication $application)
    {
        $this->id = Uuid::v7();
        $this->application = $application;
        $this->createdAt = new \DateTimeImmutable();
        $this->secretRotatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getApplication(): ?PartnerApplication
    {
        return $this->application;
    }

    public function getEncryptedUrl(): string
    {
        return $this->encryptedUrl;
    }

    public function getUrlHost(): string
    {
        return $this->urlHost;
    }

    public function setUrl(string $encryptedUrl, string $host): self
    {
        $this->encryptedUrl = $encryptedUrl;
        $this->urlHost = $host;

        return $this;
    }

    /** @return list<string> */
    public function getEvents(): array
    {
        return $this->events;
    }

    /** @param list<string> $events */
    public function setEvents(array $events): self
    {
        $this->events = array_values($events);

        return $this;
    }

    public function listensTo(string $event): bool
    {
        return $this->active && \in_array($event, $this->events, true);
    }

    public function getEncryptedSecret(): string
    {
        return $this->encryptedSecret;
    }

    public function setEncryptedSecret(string $encryptedSecret): self
    {
        $this->encryptedSecret = $encryptedSecret;
        $this->secretRotatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getSecretRotatedAt(): \DateTimeImmutable
    {
        return $this->secretRotatedAt;
    }
}
