<?php

declare(strict_types=1);

namespace App\Social\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Un relevé daté des statistiques d'une publication (SOC-3, D14 contrainte 2).
 *
 * **Pourquoi des instantanés et pas un compteur mis à jour.** Les plateformes ne rendent leurs
 * statistiques que sur une fenêtre limitée. Sans instantanés pris dès le premier jour, l'historique
 * n'existera pas — et il sera irrattrapable, parce que personne ne peut retrouver la portée d'un
 * message d'il y a six mois. Un compteur écrasé à chaque passage donnerait la valeur du jour et
 * effacerait la courbe, qui est justement ce qu'on cherche.
 *
 * **Pourquoi la charge brute en plus de la vue normalisée.** Les définitions changent entre versions
 * d'API. Le jour où « portée » ne veut plus dire la même chose, la charge brute est la seule façon de
 * s'en apercevoir et de recalculer le passé. La vue normalisée est une interprétation ; on conserve
 * donc ce qui l'a produite.
 *
 * Aucune écriture en API : un relevé naît d'une collecte planifiée. Laisser un client écrire ses
 * propres chiffres ferait mentir la seule table sur laquelle on ira chercher la vérité.
 *
 * Cloisonnement par la chaîne `publication` → `post` : le relevé ne porte pas d'établissement en
 * propre, pour qu'il ne puisse jamais diverger de celui du message.
 */
#[ORM\Entity]
#[ORM\Table(name: 'social_metric_snapshot')]
#[ORM\Index(name: 'idx_social_snapshot_publication_collected', columns: ['publication_id', 'collected_at'])]
#[ApiResource(
    shortName: 'SocialMetricSnapshot',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'social.read_post')"),
        new Get(security: "is_granted('PERM', 'social.read_post')"),
    ],
    normalizationContext: ['groups' => ['social_snapshot:read']],
)]
class SocialMetricSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['social_snapshot:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SocialPublication::class)]
    #[ORM\JoinColumn(name: 'publication_id', nullable: false)]
    #[Groups(['social_snapshot:read'])]
    private ?SocialPublication $publication = null;

    #[ORM\Column(name: 'collected_at', type: 'datetime_immutable')]
    #[Groups(['social_snapshot:read'])]
    private \DateTimeImmutable $collectedAt;

    /**
     * Compteurs **nullables** : « zéro » et « ce réseau ne rend pas cette métrique » sont deux faits
     * différents. Les confondre fabriquerait des moyennes fausses dont personne ne saurait expliquer
     * l'écart. Aucun des deux réseaux ouverts ne rend la portée — `impressions` sera donc nul, et
     * c'est une information, pas un trou.
     */
    #[ORM\Column(name: 'like_count', type: 'integer', nullable: true)]
    #[Groups(['social_snapshot:read'])]
    private ?int $likes = null;

    #[ORM\Column(name: 'share_count', type: 'integer', nullable: true)]
    #[Groups(['social_snapshot:read'])]
    private ?int $shares = null;

    #[ORM\Column(name: 'reply_count', type: 'integer', nullable: true)]
    #[Groups(['social_snapshot:read'])]
    private ?int $replies = null;

    #[ORM\Column(name: 'impression_count', type: 'integer', nullable: true)]
    #[Groups(['social_snapshot:read'])]
    private ?int $impressions = null;

    /**
     * Ce que le réseau a répondu, tel quel.
     *
     * Exposé en lecture : c'est de la donnée d'audience publique, pas un secret — et le jour où un
     * chiffre normalisé paraît faux, pouvoir comparer sans ouvrir la base est ce qui permet de
     * trancher en une minute plutôt qu'en une demi-journée.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'raw_payload', type: 'json')]
    #[Groups(['social_snapshot:read'])]
    private array $rawPayload = [];

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->collectedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublication(): ?SocialPublication
    {
        return $this->publication;
    }

    public function setPublication(?SocialPublication $publication): self
    {
        $this->publication = $publication;

        return $this;
    }

    public function getCollectedAt(): \DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function setCollectedAt(\DateTimeImmutable $collectedAt): self
    {
        $this->collectedAt = $collectedAt;

        return $this;
    }

    public function getLikes(): ?int
    {
        return $this->likes;
    }

    public function setLikes(?int $likes): self
    {
        $this->likes = $likes;

        return $this;
    }

    public function getShares(): ?int
    {
        return $this->shares;
    }

    public function setShares(?int $shares): self
    {
        $this->shares = $shares;

        return $this;
    }

    public function getReplies(): ?int
    {
        return $this->replies;
    }

    public function setReplies(?int $replies): self
    {
        $this->replies = $replies;

        return $this;
    }

    public function getImpressions(): ?int
    {
        return $this->impressions;
    }

    public function setImpressions(?int $impressions): self
    {
        $this->impressions = $impressions;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }

    /** @param array<string, mixed> $rawPayload */
    public function setRawPayload(array $rawPayload): self
    {
        $this->rawPayload = $rawPayload;

        return $this;
    }
}
