<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Boutique\State\CreerCompteClientProcessor;
use App\Boutique\State\MeCompteClientProvider;
use App\Boutique\State\VerifyAccountEmailProcessor;
use App\Boutique\State\MesBilletsProvider;
use App\Boutique\State\MesCommandesProvider;
use App\Boutique\State\MySubscriptionsProvider;
use App\Boutique\State\SouscrireAbonnementEnLigneProcessor;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Compte client final (US-L8-04/09/10, RG-M3-06/10/12/17). Satellite fin `OneToOne` de
 * `App\Securite\Entity\Utilisateur` (§0 décision n°3 du plan) : mot de passe, hachage, JWT restent
 * entièrement portés par le socle. `client` dénormalise `utilisateur.clientLie` pour des jointures
 * rapides côté espace client.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_compte_client')]
#[ORM\UniqueConstraint(name: 'uniq_compte_client_utilisateur', columns: ['utilisateur_id'])]
#[ORM\UniqueConstraint(name: 'uniq_compte_client_franceconnect', columns: ['france_connect_id'])]
#[ApiResource(
    shortName: 'CompteClient',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'boutique.lire')"),
        new Get(security: "is_granted('PERM', 'boutique.lire') or (is_granted('PERM', 'boutique.lire_soi') and object.estCelui(user))"),
        new Post(
            uriTemplate: '/boutique/comptes',
            read: false,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: CreerCompteClientProcessor::class,
        ),
        // ⚠ PUBLIQUE PAR NECESSITE : on clique ce lien depuis sa boite mail, donc sans session.
        //    C'est le jeton qui authentifie, et lui seul — d'ou le debit limite dans le processeur.
        new Post(
            uriTemplate: '/boutique/comptes/verifier-email',
            read: false,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: VerifyAccountEmailProcessor::class,
        ),
        new Get(
            uriTemplate: '/boutique/comptes/me',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: MeCompteClientProvider::class,
        ),
        new Get(
            uriTemplate: '/boutique/comptes/me/commandes',
            security: "is_granted('IS_AUTHENTICATED_FULLY') and is_granted('PERM', 'boutique.lire_soi')",
            provider: MesCommandesProvider::class,
        ),
        new Get(
            uriTemplate: '/boutique/comptes/me/billets',
            security: "is_granted('IS_AUTHENTICATED_FULLY') and is_granted('PERM', 'boutique.lire_soi')",
            provider: MesBilletsProvider::class,
        ),
        new Get(
            uriTemplate: '/boutique/comptes/me/abonnements',
            security: "is_granted('IS_AUTHENTICATED_FULLY') and is_granted('PERM', 'boutique.lire_soi')",
            provider: MySubscriptionsProvider::class,
        ),
        // RG-M3-12/17 (CA-13) : sécurité déclarée PUBLIC_ACCESS, le blocage invité est un contrôle
        // impératif dans le handler (message explicite « création de compte requise »), même esprit
        // que les contrôles impératifs déjà pratiqués par FicheClient360Provider/PmvProvider.
        new Post(
            uriTemplate: '/boutique/abonnements/souscrire',
            read: false,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: SouscrireAbonnementEnLigneProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['compte:read']],
    denormalizationContext: ['groups' => ['compte:write']],
)]
class CompteClient
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['compte:read', 'panier:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['compte:read'])]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['compte:read'])]
    private ?Client $client = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['compte:read'])]
    private ?string $franceConnectId = null;

    #[ORM\ManyToOne(targetEntity: Vitrine::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vitrine $vitrineCreation = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    /**
     * Le moment ou l'adresse a ete prouvee. `null` = jamais confirmee.
     *
     * ⚠ AUCUN GROUPE DE SERIALISATION : ni lue ni ecrite par l'API. Un champ « je suis verifie »
     * qu'un compte pourrait ecrire lui-meme ne prouverait rien ; il se pose par
     * `VerifyAccountEmailProcessor`, qui exige le jeton recu par courriel.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): self
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
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

    public function getFranceConnectId(): ?string
    {
        return $this->franceConnectId;
    }

    public function setFranceConnectId(?string $franceConnectId): self
    {
        $this->franceConnectId = $franceConnectId;

        return $this;
    }

    public function getVitrineCreation(): ?Vitrine
    {
        return $this->vitrineCreation;
    }

    public function setVitrineCreation(?Vitrine $vitrineCreation): self
    {
        $this->vitrineCreation = $vitrineCreation;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    /** Vrai si ce compte correspond à l'utilisateur connecté (permissions `_soi`). */
    public function estCelui(mixed $user): bool
    {
        if (!$user instanceof Utilisateur || $this->utilisateur === null) {
            return false;
        }

        return $this->utilisateur->getId()->equals($user->getId());
    }
}
