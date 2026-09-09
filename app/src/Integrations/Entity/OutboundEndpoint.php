<?php

declare(strict_types=1);

namespace App\Integrations\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Integrations\Enum\EndpointKind;
use App\Integrations\State\OutboundEndpointProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UNE DESTINATION SORTANTE — un canal Slack, Teams ou Discord, ou l'automate maison d'un client.
 *
 * ── ⚠ L'URL EST UN SECRET, ET ELLE NE RESSORT JAMAIS ────────────────────────────────────────────
 *
 * Une URL de webhook entrant N'EST PAS UNE ADRESSE : c'est une CLÉ. Quiconque la détient peut écrire
 * dans le canal au nom de l'établissement, sans authentification, indéfiniment — et sans que
 * personne ne puisse dire qui l'a fait.
 *
 * C'est pour cette raison qu'elle ne vit pas dans `FonctionnaliteEtablissement::$parametres`, où
 * l'on aurait spontanément mis un réglage de module : ce champ est dans le groupe
 * `fonctionnalite:read`, dont la collection est lisible par **tout utilisateur authentifié**. Un
 * agent d'accueil y aurait lu de quoi publier au nom de sa direction.
 *
 * Ici :
 *   - `$urlChiffree` est chiffrée au repos et n'appartient à AUCUN groupe de sérialisation ;
 *   - `$url` (en écriture seule) la reçoit et disparaît — elle n'est jamais stockée telle quelle ;
 *   - `$hote` est le seul reflet qui ressort, et il ne permet rien : il dit « hooks.slack.com »,
 *     pas le jeton qui suit.
 *
 * ⚠ **ON NE PEUT PAS RELIRE UNE URL POUR LA MODIFIER**, et c'est délibéré. Un formulaire qui la
 * réafficherait pour édition la remettrait sur le réseau à chaque ouverture d'écran, au premier
 * utilisateur venu qui a le droit de lire. Modifier une destination, c'est en recoller une.
 *
 * ── LES ÉVÉNEMENTS SOUSCRITS ────────────────────────────────────────────────────────────────────
 *
 * `$evenements` liste des noms du catalogue (`booking.confirmed`, `invoice.overdue`…). Vide = AUCUN
 * envoi, jamais « tous » : une destination fraîchement créée ne doit rien déverser avant que
 * quelqu'un ait choisi quoi. C'est la même règle contre-intuitive que les promotions du catalogue,
 * et elle se prend dans le même sens — le vide veut dire « rien », pas « tout ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'integrations_outbound_endpoint')]
#[ORM\Index(name: 'idx_outbound_endpoint_establishment', columns: ['establishment_id'])]
#[ApiResource(
    shortName: 'IntegrationOutboundEndpoint',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'connecteurs.lire')"),
        new Get(security: "is_granted('PERM', 'connecteurs.lire')"),
        new Post(
            security: "is_granted('PERM', 'connecteurs.gerer_destination')",
            processor: OutboundEndpointProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'connecteurs.gerer_destination')",
            processor: OutboundEndpointProcessor::class,
        ),
        new Delete(security: "is_granted('PERM', 'connecteurs.gerer_destination')"),
    ],
    normalizationContext: ['groups' => ['endpoint:read']],
    denormalizationContext: ['groups' => ['endpoint:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['kind' => 'exact', 'actif' => 'exact'])]
class OutboundEndpoint
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['endpoint:read'])]
    private Uuid $id;

    /**
     * ⚠ HORS DU GROUPE D'ÉCRITURE, TOUJOURS DÉRIVÉ CÔTÉ SERVEUR depuis l'établissement actif. Le
     * laisser écrire permettrait de déposer une destination dans l'établissement d'un voisin, qui
     * recevrait alors ses propres événements chez soi.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['endpoint:read'])]
    private ?Etablissement $establishment = null;

    #[ORM\Column(length: 16, enumType: EndpointKind::class)]
    #[Groups(['endpoint:read', 'endpoint:write'])]
    private EndpointKind $kind = EndpointKind::Generique;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Donnez un nom à cette destination : « #accueil-piscine » se relit, une URL non.')]
    #[Groups(['endpoint:read', 'endpoint:write'])]
    private string $libelle = '';

    /** ⚠ AUCUN GROUPE DE SÉRIALISATION. Elle n'entre ni ne sort par l'API. */
    #[ORM\Column(type: 'text')]
    private string $urlChiffree = '';

    /**
     * L'hôte seul, en clair, pour que l'écran puisse montrer VERS QUOI pointe la destination sans
     * montrer la clé. « hooks.slack.com » ne permet d'écrire nulle part.
     */
    #[ORM\Column(length: 180)]
    #[Groups(['endpoint:read'])]
    private string $hote = '';

    /**
     * @var list<string> Noms d'événements du catalogue. Vide = aucun envoi.
     */
    #[ORM\Column]
    #[Groups(['endpoint:read', 'endpoint:write'])]
    private array $evenements = [];

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['endpoint:read', 'endpoint:write'])]
    private bool $actif = true;

    #[ORM\Column]
    #[Groups(['endpoint:read'])]
    private \DateTimeImmutable $creeLe;

    /**
     * Quand un message est-il parti pour la dernière fois, et qu'a dit le dernier échec ?
     *
     * ⚠ SANS CES DEUX CHAMPS, UNE DESTINATION MUETTE EST INDISCERNABLE D'UNE DESTINATION CALME. Un
     * canal qui ne reçoit rien parce que l'URL a été révoquée ressemble exactement à un canal sur
     * lequel rien ne s'est produit — et on ne s'en aperçoit que le jour où l'on comptait sur
     * l'alerte.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['endpoint:read'])]
    private ?\DateTimeImmutable $dernierEnvoiLe = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['endpoint:read'])]
    private ?string $dernierEchec = null;

    /**
     * L'URL en clair, À L'ÉCRITURE SEULEMENT. Jamais persistée telle quelle, jamais relue.
     * `OutboundEndpointProcessor` la chiffre puis la laisse tomber.
     */
    // ⚠ `requireTld: true` REFUSE UN HÔTE SANS DOMAINE PUBLIC — `https://localhost/hook`,
    // `https://intranet/hook`. Ce n'était pas le cas avant le 07/09, et ce ne l'était pas par
    // décision : l'option était absente, Symfony 7.1 en déprécie le silence et changera le défaut à
    // `true`. Sans ce mot-là, la règle aurait basculé à la faveur d'une montée de version, c'est-à-dire
    // au pire moment. `Social/Entity/SocialAccount.php` le passe déjà explicitement.
    //
    // L'`https` et la présence d'un hôte, eux, sont exigés par `OutboundEndpointProcessor` : cette
    // URL vaut un mot de passe, elle ne peut pas circuler en clair.
    #[Assert\Url(requireTld: true, message: 'L\'adresse du webhook doit être une URL complète, en https, avec un nom de domaine public.')]
    #[Groups(['endpoint:write'])]
    public ?string $url = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->creeLe = new \DateTimeImmutable();
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

    public function getKind(): EndpointKind
    {
        return $this->kind;
    }

    public function setKind(EndpointKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getUrlChiffree(): string
    {
        return $this->urlChiffree;
    }

    public function setUrlChiffree(string $chiffree): self
    {
        $this->urlChiffree = $chiffree;

        return $this;
    }

    public function getHote(): string
    {
        return $this->hote;
    }

    public function setHote(string $hote): self
    {
        $this->hote = $hote;

        return $this;
    }

    /** @return list<string> */
    public function getEvenements(): array
    {
        return $this->evenements;
    }

    /** @param list<string> $evenements */
    public function setEvenements(array $evenements): self
    {
        $this->evenements = array_values(array_unique(array_filter(
            $evenements,
            static fn (mixed $n): bool => \is_string($n) && $n !== '',
        )));

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getDernierEnvoiLe(): ?\DateTimeImmutable
    {
        return $this->dernierEnvoiLe;
    }

    public function setDernierEnvoiLe(?\DateTimeImmutable $quand): self
    {
        $this->dernierEnvoiLe = $quand;

        return $this;
    }

    public function getDernierEchec(): ?string
    {
        return $this->dernierEchec;
    }

    /**
     * ⚠ TRONQUÉ, ET POUR UNE RAISON. Un corps de réponse d'erreur peut contenir l'URL appelée —
     * donc la clé. On garde de quoi diagnostiquer, pas de quoi fuir.
     */
    public function setDernierEchec(?string $raison): self
    {
        $this->dernierEchec = $raison === null ? null : mb_substr($raison, 0, 200);

        return $this;
    }

    /** Cette destination écoute-t-elle cet événement ? */
    public function ecoute(string $nomEvenement): bool
    {
        return $this->actif && \in_array($nomEvenement, $this->evenements, true);
    }
}
