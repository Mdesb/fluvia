<?php

declare(strict_types=1);

namespace App\Social\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Social\Enum\SocialPostStatus;
use App\Social\Enum\SocialPublicationStatus;
use App\Social\State\SocialPostProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un message rédigé une fois, destiné à N réseaux (D14).
 *
 * **Un post, N publications.** Le message part vers cinq réseaux ; trois réussissent, un dépasse son
 * quota, un cinquième échoue sur un jeton expiré. Chaque réseau a donc sa propre ligne
 * (`SocialPublication`), son état, son identifiant distant et son erreur. Modéliser « un post publié ou
 * non » perdrait l'information exacte qui compte le jour de l'incident — et c'est aussi cette ligne qui
 * portera les statistiques, donc la jointure entre ce qu'on a publié et ce que ça a produit.
 *
 * `status` n'est qu'un **résumé** recalculé depuis les publications (`recomputeStatus()`), jamais une
 * vérité posée à la main : deux sources qui peuvent diverger sur le même fait finissent toujours par
 * diverger.
 *
 * `establishment` est dérivé côté serveur, hors groupe d'écriture (invariant noyau commun #1). Les
 * comptes visés arrivent, eux, du client : ils sont donc revérifiés un par un contre le périmètre par
 * `SocialPostProcessor`. Un compte visé est une entité résolue depuis l'entrée client — exactement la
 * forme des seize IDOR trouvés dans ce dépôt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'social_post')]
#[ORM\Index(name: 'idx_social_post_establishment_created', columns: ['establishment_id', 'created_at'])]
#[ApiResource(
    shortName: 'SocialPost',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'social.read_post')"),
        new Get(security: "is_granted('PERM', 'social.read_post')"),
        new Post(security: "is_granted('PERM', 'social.publish')", processor: SocialPostProcessor::class),
    ],
    normalizationContext: ['groups' => ['social_post:read']],
    denormalizationContext: ['groups' => ['social_post:write']],
)]
// Pas de PATCH ni de DELETE dans ce lot, et c'est délibéré : modifier le texte d'un message déjà parti
// sur trois réseaux ne le modifierait sur aucun des trois, mais changerait ce que la plateforme
// prétend avoir publié. La correction d'un brouillon viendra avec SOC-2, restreinte aux messages dont
// aucune publication n'est engagée.
class SocialPost
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['social_post:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    #[Groups(['social_post:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(name: 'body', type: 'text')]
    #[Assert\NotBlank(message: 'social.error.body_required')]
    #[Groups(['social_post:read', 'social_post:write'])]
    private string $body = '';

    /**
     * Date de parution souhaitée. Nulle = dès que possible. Aucune assertion d'horloge n'est faite sur
     * ce champ dans la suite fonctionnelle (D20) : c'est l'ordonnanceur qui le lit, pas les tests.
     */
    #[ORM\Column(name: 'scheduled_for', type: 'datetime_immutable', nullable: true)]
    #[Groups(['social_post:read', 'social_post:write'])]
    private ?\DateTimeImmutable $scheduledFor = null;

    #[ORM\Column(length: 32, enumType: SocialPostStatus::class, options: ['default' => 'draft'])]
    #[Groups(['social_post:read'])]
    private SocialPostStatus $status = SocialPostStatus::Draft;

    /** @var Collection<int, SocialPublication> */
    #[ORM\OneToMany(mappedBy: 'post', targetEntity: SocialPublication::class, cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['social_post:read'])]
    private Collection $publications;

    /**
     * Comptes visés — champ transitoire (jamais mappé Doctrine), consommé par le processor qui en
     * dérive les publications. Les valeurs arrivent en IRI et sont revérifiées contre le périmètre.
     *
     * @var list<SocialAccount>
     */
    #[Groups(['social_post:write'])]
    private array $targetAccounts = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Groups(['social_post:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Groups(['social_post:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->publications = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getScheduledFor(): ?\DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function setScheduledFor(?\DateTimeImmutable $scheduledFor): self
    {
        $this->scheduledFor = $scheduledFor;

        return $this;
    }

    public function getStatus(): SocialPostStatus
    {
        return $this->status;
    }

    public function setStatus(SocialPostStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    /** @return Collection<int, SocialPublication> */
    public function getPublications(): Collection
    {
        return $this->publications;
    }

    public function addPublication(SocialPublication $publication): self
    {
        if (!$this->publications->contains($publication)) {
            $this->publications->add($publication);
            $publication->setPost($this);
        }

        return $this;
    }

    /** @return list<SocialAccount> */
    public function getTargetAccounts(): array
    {
        return $this->targetAccounts;
    }

    /** @param list<SocialAccount> $targetAccounts */
    public function setTargetAccounts(array $targetAccounts): self
    {
        $this->targetAccounts = $targetAccounts;

        return $this;
    }

    /**
     * Recalcule le résumé depuis les lignes. Ordre des questions volontaire : « tout est terminé ? »
     * avant « tout a réussi ? », parce qu'un message dont une seule ligne reste en attente n'est ni
     * publié ni échoué, et l'annoncer publié serait faux pendant les quelques minutes qui comptent.
     */
    public function recomputeStatus(): self
    {
        if ($this->publications->isEmpty()) {
            $this->status = $this->scheduledFor !== null ? SocialPostStatus::Scheduled : SocialPostStatus::Draft;

            return $this;
        }

        $succeeded = 0;
        $ended = 0;
        foreach ($this->publications as $publication) {
            if ($publication->getStatus()->isTerminal()) {
                ++$ended;
            }
            if ($publication->getStatus() === SocialPublicationStatus::Published) {
                ++$succeeded;
            }
        }
        $total = $this->publications->count();

        if ($ended < $total) {
            $this->status = $ended === 0 && $succeeded === 0
                ? ($this->scheduledFor !== null ? SocialPostStatus::Scheduled : SocialPostStatus::Draft)
                : SocialPostStatus::Publishing;

            return $this;
        }

        $this->status = match (true) {
            $succeeded === $total => SocialPostStatus::Published,
            $succeeded === 0 => SocialPostStatus::Failed,
            default => SocialPostStatus::PartiallyFailed,
        };

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
