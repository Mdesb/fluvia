<?php

declare(strict_types=1);

namespace App\Calendar\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Calendar\State\IcsSubscriptionProvider;
use App\Calendar\State\RegenerateIcsSubscriptionProcessor;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * L'ABONNEMENT ICS : une URL à coller dans Google, Apple ou Outlook.
 *
 * ── POURQUOI UNE URL À JETON PLUTÔT QU'UNE AUTHENTIFICATION ─────────────────────────────────────
 *
 * Aucun agenda du marché ne sait présenter un jeton JWT ni un en-tête `X-Etablissement` : ils font
 * un GET anonyme, toutes les heures, sur une URL. Un flux ICS est donc nécessairement une **URL
 * capacitaire** — l'adresse EST le secret. C'est le mécanisme employé par tout le monde, y compris
 * par le produit dont Maxime nous a montré l'écran.
 *
 * ── CE QUE ÇA COÛTE, ET COMMENT ON LE BORNE ─────────────────────────────────────────────────────
 *
 * Le jeton est stocké EN CLAIR, et c'est un choix assumé : l'exploitant doit pouvoir relire son URL
 * pour la recoller sur un second appareil. Le hacher rendrait l'écran incapable de la réafficher,
 * donc la fonctionnalité inutilisable après le premier collage. En contrepartie :
 *
 *   - le flux est en LECTURE SEULE et ne rend QUE ce que son porteur voit déjà — mêmes bornes de
 *     cloisonnement, même filtre de propriété ;
 *   - il est RÉVOCABLE en un geste : régénérer casse l'ancienne URL immédiatement ;
 *   - il est nominatif ET borné à un établissement : une URL qui fuite n'ouvre pas le groupe.
 *
 * Ce qu'on ne fait PAS : mettre le jeton dans un paramètre de requête. Il est dans le CHEMIN, parce
 * qu'une chaîne de requête finit dans les journaux d'accès de tous les intermédiaires.
 */
#[ORM\Entity]
#[ORM\Table(name: 'calendar_ics_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_ics_token', fields: ['token'])]
#[ORM\UniqueConstraint(name: 'uniq_calendar_ics_user_establishment', fields: ['user', 'establishment'])]
#[ApiResource(
    shortName: 'IcsSubscription',
    operations: [
        new GetCollection(
            uriTemplate: '/calendar/ics-subscription',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: IcsSubscriptionProvider::class,
        ),
        new Get(security: "is_granted('IS_AUTHENTICATED_FULLY')"),
        new Post(
            uriTemplate: '/calendar/ics-subscription/regenerate',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            processor: RegenerateIcsSubscriptionProcessor::class,
            read: false,
            deserialize: false,
        ),
    ],
    routePrefix: '',
    normalizationContext: ['groups' => ['calendar_ics:read']],
    // GROUPE D'ÉCRITURE VIDE, ET DÉLIBÉRÉMENT (D41). Aucune propriété ne porte
    // `calendar_ics:write` : rien n'est donc écrivable depuis le corps d'une requête. Sans ce
    // contexte, API Platform rendrait écrivable toute propriété dotée d'un mutateur —
    // `utilisateur` et `etablissement` compris — et l'appelant choisirait à quel compte
    // appartient un jeton d'agenda. C'est l'ABSENCE de déclaration qui expose, et rien dans le
    // fichier ne le signale : c'est pourquoi elle est écrite ici plutôt que sous-entendue.
    denormalizationContext: ['groups' => ['calendar_ics:write']],
)]
class IcsSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['calendar_ics:read'])]
    private Uuid $id;

    // `onDelete: CASCADE` : un jeton d'agenda qui survit à son porteur est une URL anonyme qui
    // rend des données au nom d'un compte qui n'existe plus.
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $user = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $establishment = null;

    /** 48 caractères hexadécimaux tirés de `random_bytes` — jamais d'identifiant deviné. */
    #[ORM\Column(length: 64)]
    #[Groups(['calendar_ics:read'])]
    private string $token = '';

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['calendar_ics:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->token = bin2hex(random_bytes(24));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): ?Utilisateur
    {
        return $this->user;
    }

    public function setUser(?Utilisateur $user): self
    {
        $this->user = $user;

        return $this;
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

    public function getToken(): string
    {
        return $this->token;
    }

    /** Régénérer casse l'ancienne URL immédiatement : c'est la révocation. */
    public function regenerate(): self
    {
        $this->token = bin2hex(random_bytes(24));
        $this->createdAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Le chemin relatif à coller dans « S'abonner à un agenda ». */
    #[Groups(['calendar_ics:read'])]
    public function getPath(): string
    {
        return '/calendar/ics/' . $this->token . '.ics';
    }
}
