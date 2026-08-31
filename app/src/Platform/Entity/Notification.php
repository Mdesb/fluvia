<?php

declare(strict_types=1);

namespace App\Platform\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Platform\Enum\NotificationSeverity;
use App\Platform\State\MarkAllNotificationsReadProcessor;
use App\Platform\State\MarkNotificationReadProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * UNE NOTIFICATION EST UN ÉVÉNEMENT QU'ON A DÉCIDÉ DE MONTRER À QUELQU'UN.
 *
 * ── CE QU'ON NE CONSTRUIT PAS, ET C'EST LE POINT DE DÉPART ──────────────────────────────────────
 *
 * Pas une table que rien ne remplit. Le dépôt en compte déjà trop : vingt-trois commandes planifiées
 * dont aucune ne se déclenche, des abonnés inertes, des mécanismes qui ont l'air de fonctionner
 * parce qu'ils existent. Une cloche avec une pastille rouge rendrait celui-ci plus crédible que les
 * autres, pas plus vivant.
 *
 * Le producteur existe déjà : le bus d'événements de domaine. Une notification n'invente rien, elle
 * choisit — voir {@see \App\Platform\Notification\NotificationRule}.
 *
 * ── LE DESTINATAIRE EST UNE PERSONNE, PAS UN RÔLE ───────────────────────────────────────────────
 *
 * Question posée par allaccess-8e, et elle est structurante. Adresser à un rôle obligerait de toute
 * façon à tenir l'état « lue » **par utilisateur** — sinon le premier qui lit efface la pastille des
 * autres. On aurait donc les deux tables et une jointure, pour la même information.
 *
 * On écrit donc une ligne par destinataire au moment de la création. Le coût de cette duplication
 * est réel et il est borné par le critère d'admission : un événement ne devient notification que
 * s'il appelle **le geste d'une personne** et qu'il est **rare**. `access.denied` en produirait
 * plusieurs par minute à l'ouverture des portes et personne n'agit sur un refus isolé ; il n'entre
 * pas.
 *
 * ── DEUX ÉTATS, ET RIEN D'AUTRE ─────────────────────────────────────────────────────────────────
 *
 * Lue ou non. Pas d'« archivée », pas de « masquée » : chaque état supplémentaire est une décision
 * de plus à prendre pour l'utilisateur et un chemin de plus à tenir. Et **aucune suppression** — une
 * notification efface un fait qui a eu lieu, ce que ni l'exploitant ni nous n'avons à faire.
 *
 * ── LE TRI EST DÉCLARÉ DÈS LE DÉPART, ET CE N'EST PAS UN DÉTAIL ─────────────────────────────────
 *
 * ⚠ Sans `OrderFilter` sur l'horodatage et avec le plafond de pagination, la cloche montrerait les
 * trente notifications **les plus anciennes**. C'est exactement le défaut corrigé sur les passages
 * cette semaine : un écran qui affiche des lignes vraies, dans un ordre qui les rend inutiles, ne se
 * signale jamais comme cassé.
 *
 * ── LE CLOISONNEMENT EST DOUBLE ─────────────────────────────────────────────────────────────────
 *
 * Par destinataire ET par établissement actif — voir {@see \App\Platform\Doctrine\NotificationScopeExtension}.
 * Le premier est une règle de confidentialité, le second une règle d'attention : une notification
 * d'un site que je ne regarde pas n'a rien à faire dans ma cloche.
 */
#[ORM\Entity]
#[ORM\Table(name: 'platform_notification')]
#[ORM\Index(name: 'idx_notification_destinataire_lue', columns: ['destinataire_id', 'lue'])]
#[ORM\Index(name: 'idx_notification_horodatage', columns: ['horodatage'])]
#[ApiResource(
    shortName: 'Notification',
    operations: [
        new GetCollection(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Post(
            uriTemplate: '/notifications/{id}/lue',
            read: true,
            input: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            processor: MarkNotificationReadProcessor::class,
        ),
        new Post(
            uriTemplate: '/notifications/tout-lu',
            read: false,
            input: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            processor: MarkAllNotificationsReadProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['notification:read']],
    // ⚠ AUCUNE PROPRIÉTÉ N'EST DANS CE GROUPE, ET C'EST VOULU. Rien de cette entité ne s'écrit par
    // l'API : elle naît d'un événement, et son seul changement d'état passe par les deux opérations
    // ci-dessus. Le groupe est déclaré vide plutôt qu'omis — une absence de déclaration expose par
    // défaut (D41), et c'est la voie la moins visible.
    denormalizationContext: ['groups' => ['notification:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['lue' => 'exact', 'gravite' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['horodatage' => 'DESC'], arguments: ['orderParameterName' => 'order'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['notification:read'])]
    private Uuid $id;

    /**
     * ⚠ Une personne nommée, jamais un rôle. Voir l'en-tête : l'état « lue » est par nature
     * individuel, et adresser à un rôle reviendrait à tenir la même information avec une table de
     * plus.
     */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $destinataire;

    /** Stampé au serveur depuis l'enveloppe de l'événement (D41) : jamais écrit par un appelant. */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Etablissement $etablissement;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['notification:read'])]
    private \DateTimeImmutable $horodatage;

    #[ORM\Column(length: 16, enumType: NotificationSeverity::class, options: ['default' => 'info'])]
    #[Groups(['notification:read'])]
    private NotificationSeverity $gravite = NotificationSeverity::Info;

    #[ORM\Column(length: 160)]
    #[Groups(['notification:read'])]
    private string $titre = '';

    /**
     * La phrase qui dit quoi faire, pas le code de l'événement. « La facture 2026-014 de Martin SAS
     * est en retard de 12 jours » plutôt que « invoice.overdue ».
     */
    #[ORM\Column(type: 'text')]
    #[Groups(['notification:read'])]
    private string $texte = '';

    /**
     * ⚠ SANS DESTINATION, UNE NOTIFICATION EST DU DÉCOR. C'est ce qui la sépare d'une ligne de
     * journal : elle doit mener à l'écran où le geste se fait. Le couple est celui de la navigation
     * du frontal — `{ ecran: 'recouvrement', params: { impaye: '<uuid>' } }`.
     */
    #[ORM\Column(length: 64)]
    #[Groups(['notification:read'])]
    private string $ecran = '';

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    #[Groups(['notification:read'])]
    private array $params = [];

    /** Le nom de l'événement d'origine : on doit pouvoir remonter au fait depuis la phrase. */
    #[ORM\Column(length: 64)]
    #[Groups(['notification:read'])]
    private string $source = '';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['notification:read'])]
    private bool $lue = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['notification:read'])]
    private ?\DateTimeImmutable $luLe = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDestinataire(): Utilisateur
    {
        return $this->destinataire;
    }

    public function setDestinataire(Utilisateur $destinataire): self
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getEtablissement(): Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }

    public function getGravite(): NotificationSeverity
    {
        return $this->gravite;
    }

    public function setGravite(NotificationSeverity $gravite): self
    {
        $this->gravite = $gravite;

        return $this;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;

        return $this;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function setTexte(string $texte): self
    {
        $this->texte = $texte;

        return $this;
    }

    public function getEcran(): string
    {
        return $this->ecran;
    }

    public function setEcran(string $ecran): self
    {
        $this->ecran = $ecran;

        return $this;
    }

    /** @return array<string, scalar|null> */
    public function getParams(): array
    {
        return $this->params;
    }

    /** @param array<string, scalar|null> $params */
    public function setParams(array $params): self
    {
        $this->params = $params;

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

    public function isLue(): bool
    {
        return $this->lue;
    }

    public function getLuLe(): ?\DateTimeImmutable
    {
        return $this->luLe;
    }

    /**
     * Marquer lue est **idempotent** : la date de première lecture ne bouge plus.
     *
     * Un second appel ne doit pas réécrire l'horodatage — la cloche renvoie parfois deux fois le même
     * clic, et « lu à 14h02 » puis « lu à 14h02 et 3 secondes » n'apporte rien tout en faisant écrire
     * la base pour rien.
     */
    public function marquerLue(\DateTimeImmutable $quand): self
    {
        if (!$this->lue) {
            $this->lue = true;
            $this->luLe = $quand;
        }

        return $this;
    }
}
