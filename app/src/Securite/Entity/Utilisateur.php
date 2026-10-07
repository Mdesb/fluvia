<?php

declare(strict_types=1);

namespace App\Securite\Entity;

use App\Securite\Enum\AccountKind;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Securite\Enum\StatutUtilisateur;
use App\Securite\State\MfaActivationProcessor;
use App\Securite\State\MfaConfirmationProcessor;
use App\Securite\State\MfaDesactivationProcessor;
use App\Securite\State\MfaReinitialisationProcessor;
use App\Securite\State\ReinvitationProcessor;
use App\Securite\State\SuspensionUtilisateurProcessor;
use App\Securite\State\UtilisateurProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Utilisateur authentifiable (US-L0-02). Le mot de passe est haché (RG-SOCLE-06) et n'est JAMAIS exposé.
 * Le verrouillage temporaire s'appuie sur tentativesEchouees + verrouilleJusqua.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sec_utilisateur')]
#[ORM\UniqueConstraint(name: 'uniq_utilisateur_email', columns: ['email'])]
#[ApiResource(
    shortName: 'Utilisateur',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'securite.gerer')"),
        new Get(security: "is_granted('PERM', 'securite.gerer')"),
        new Post(
            security: "is_granted('PERM', 'securite.gerer')",
            processor: UtilisateurProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'securite.gerer')",
            processor: UtilisateurProcessor::class,
        ),
        // Cycle de vie (RG-M8-01, US-L7-03) : suspension à effet immédiat (garde dernier admin,
        // tokenVersion++), réactivation, ré-invitation (nouveau jeton).
        new Post(
            uriTemplate: '/utilisateurs/{id}/suspendre',
            read: true,
            input: false,
            security: "is_granted('PERM', 'securite.gerer')",
            processor: SuspensionUtilisateurProcessor::class,
            normalizationContext: ['groups' => ['utilisateur:read']],
        ),
        new Post(
            uriTemplate: '/utilisateurs/{id}/reactiver',
            read: true,
            input: false,
            security: "is_granted('PERM', 'securite.gerer')",
            processor: SuspensionUtilisateurProcessor::class,
            normalizationContext: ['groups' => ['utilisateur:read']],
        ),
        new Post(
            uriTemplate: '/utilisateurs/{id}/reinviter',
            read: true,
            input: false,
            security: "is_granted('PERM', 'securite.gerer')",
            processor: ReinvitationProcessor::class,
            normalizationContext: ['groups' => ['utilisateur:read']],
        ),
        // MFA (RG-M8-06, US-L7-03) : activation en 2 temps (self), désactivation (self, garde
        // rôle à privilèges), réinitialisation par un administrateur (perte d'appareil).
        new Post(
            uriTemplate: '/utilisateurs/{id}/mfa/activer',
            read: true,
            input: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY') and object == user",
            processor: MfaActivationProcessor::class,
        ),
        new Post(
            uriTemplate: '/utilisateurs/{id}/mfa/confirmer',
            read: true,
            input: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY') and object == user",
            processor: MfaConfirmationProcessor::class,
            normalizationContext: ['groups' => ['utilisateur:read']],
        ),
        new Post(
            uriTemplate: '/utilisateurs/{id}/mfa/desactiver',
            read: true,
            input: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY') and object == user",
            processor: MfaDesactivationProcessor::class,
            normalizationContext: ['groups' => ['utilisateur:read']],
        ),
        new Post(
            uriTemplate: '/utilisateurs/{id}/mfa/reinitialiser',
            read: true,
            input: false,
            security: "is_granted('PERM', 'securite.gerer')",
            processor: MfaReinitialisationProcessor::class,
            normalizationContext: ['groups' => ['utilisateur:read']],
        ),
    ],
    normalizationContext: ['groups' => ['utilisateur:read']],
    denormalizationContext: ['groups' => ['utilisateur:write']],
)]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    // `ticket:read` / `message:read` : sans eux, la relation part en IRI et l'ecran d'assistance
    // ne peut pas dire QUI a ecrit — ni de quel cote poser la bulle.
    #[Groups(['utilisateur:read', 'me:read', 'affectation:read', 'ticket:read', 'message:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['utilisateur:read', 'utilisateur:write', 'me:read', 'affectation:read'])]
    private string $email = '';

    /** Mot de passe haché — jamais exposé via l'API. */
    #[ORM\Column(length: 255)]
    private string $motDePasse = '';

    /**
     * Mot de passe en clair fourni en écriture uniquement, haché par UtilisateurProcessor.
     * Non persisté.
     */
    #[Assert\NotBlank(groups: ['utilisateur:create'])]
    #[Groups(['utilisateur:write'])]
    private ?string $motDePasseClair = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    // Cinq modules referencent `Utilisateur` sans qu'aucune de ses proprietes ne leur soit
    // visible : la relation partait en IRI nue et l'ecran recevait une URL la ou il attend un
    // nom. Le plus grave etait la file d'assistance, qui affichait « non affecte » sur un
    // ticket pourtant pris en charge -- une absence deguisee en information.
    //
    // `message:read` s'ajoute a la liste de `main` : c'est lui qui nomme l'auteur de chaque bulle
    // dans la messagerie d'assistance. Sans lui, chaque message d'un interlocuteur s'affiche
    // « Auteur inconnu » -- le meme defaut, un cran plus loin dans le meme ecran.
    //
    // Seul `nom` rejoint ces groupes : une propriete sans groupe reste invisible meme quand
    // son entite est embarquee, et c'est ce qui garde le reste de la fiche hors de portee.
    // L'e-mail, en particulier, n'y est pas : lire une conversation demande de savoir qui parle,
    // pas comment le joindre.
    //
    // L'attribut tient sur UNE ligne, comme partout ailleurs dans le depot. La version repliee
    // sur plusieurs lignes ecrite d'abord etait valide pour PHP et illisible pour les outils :
    // `relations-muettes.py` cessait de voir les groupes de cette propriete, et le compte des
    // relations muettes MONTAIT au lieu de baisser. Une variante de forme qui n'apporte rien
    // coute la mesure.
    //
    // `qualif:read` / `affect:read` s'ajoutent pour la Piscine : `QualificationEncadrant::$encadrant`
    // portait deja les deux groupes, mais AUCUNE propriete d'`Utilisateur` n'y etait — la relation
    // repartait donc en IRI, et les trois surfaces de l'onglet « Qualifications d'encadrants »
    // affichaient « encadrant 5540c580… » : la liste, la modale « Renouveler le diplome » et le
    // choix de la modale d'affectation. Le repli de `libelleEncadrant()` faisait son travail ;
    // c'est la charge utile qui etait pauvre.
    //
    // ⚠ PORTEE MESUREE AVANT D'ELARGIR, parce que ces deux groupes ne sont PAS reserves a la
    // Piscine. `qualif:read` est le groupe de normalisation de TROIS ressources sans rapport —
    // `Piscine\QualificationEncadrant`, `Musee\QualificationLangueGuide`, `Compta\QualificationEquipement`
    // — et `affect:read` de `Piscine\AffectationEncadrant`, qui embarque aussi `CreneauBassin`.
    // Le nom ne sort donc que la ou un `Utilisateur` est reellement embarque sous l'un des deux :
    // `QualificationEncadrant::$encadrant`, et lui seul. `Musee\Guide::$utilisateur` porte
    // `guide:read`/`guide:write` et non `qualif:read` ; `QualificationEquipement` et `CreneauBassin`
    // ne referencent aucun `Utilisateur`. Zero surface collaterale — mais la mesure vaut pour AUJOURD'HUI :
    // brancher un `Utilisateur` sur une de ces ressources l'exposerait sans que rien ne le signale.
    //
    // L'e-mail reste dehors, pour la meme raison qu'au-dessus : nommer l'encadrant d'un creneau
    // demande de savoir QUI encadre, pas comment le joindre. Le repli du frontal le prefere quand il
    // est la (`e.email || e.nom`) et retombe sur le nom quand il ne l'est pas — c'est le cas ici.
    #[Groups(['utilisateur:read', 'utilisateur:write', 'me:read', 'caution_mouvement:read', 'activity:read', 'project_task:read', 'project:read', 'ticket:read', 'message:read', 'document_version:read', 'session:read', 'sos:read', 'qualif:read', 'affect:read'])]
    private string $nom = '';

    /**
     * Cycle de vie du compte (RG-M8-01). Remplace l'ancien booléen persisté `actif` — voir
     * `isActif()`/`setActif()` ci-dessous pour la compatibilité ascendante (fixtures socle/CRM).
     */
    #[ORM\Column(length: 12, enumType: StatutUtilisateur::class)]
    #[Groups(['utilisateur:read', 'me:read'])]
    private StatutUtilisateur $statut = StatutUtilisateur::Invite;

    /** Jeton d'invitation haché (sha256), consommé à l'activation (CA-1/CA-2). Jamais exposé. */
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $jetonInvitation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $jetonInvitationExpire = null;

    /** Mis à jour à chaque connexion réussie (2ᵉ facteur inclus), RG-M8-01. */
    #[ORM\Column(nullable: true)]
    #[Groups(['utilisateur:read'])]
    private ?\DateTimeImmutable $dernierAcces = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['utilisateur:read', 'me:read'])]
    private bool $mfaActif = false;

    // ── ÉQUIPE PLATEFORME (mono-propriétaire) : ACCÈS À TOUS LES SITES ────────────────────────────
    // Exception assumée à RG-ED-07 : tous les établissements appartiennent au même propriétaire, il
    // n'y a pas de tiers à cloisonner. `PlatformScope` est le seul lecteur ; les trois coutures du
    // contrôle d'accès (reachability, périmètre établissement, calcul des droits) court-circuitent le
    // cloisonnement pour un membre plateforme. À accorder avec parcimonie (toi + ton équipe).
    #[ORM\Column(options: ['default' => false])]
    private bool $plateforme = false;

    /** Secret TOTP chiffré au repos (libsodium) — jamais exposé en lecture API. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mfaSecret = null;

    /** @var list<string>|null Codes de récupération, hachés (sha256), usage unique chacun. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $mfaCodesRecuperation = null;

    /**
     * Invalidation de session/JWT (§2.2 plan) : incrémenté à la suspension et à la
     * réinitialisation de mot de passe ; vérifié à chaque requête authentifiée contre le claim
     * du JWT.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $tokenVersion = 0;

    /** @var list<string> Rôles de sécurité Symfony (techniques). */
    #[ORM\Column]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private array $rolesSecurite = ['ROLE_USER'];

    /**
     * EXPLOITANT OU CLIENT FINAL — la nature du compte, posée à sa création et jamais recopiée.
     *
     * ⚠ AUDIT DU 06/09, CONSTAT 4. Les comptes de la boutique publique vivaient ici sans rien qui les
     * distingue des exploitants : leur jeton franchissait toutes les portes « connecté, et rien de
     * plus » du back-office. `CustomerAccountPathListener` lit cette colonne — pas `rolesSecurite`, que
     * l'API sait écrire (`utilisateur:write`) et qu'un rôle modèle pourrait recopier.
     *
     * Lecture seule par l'API : la nature se pose par le chemin qui crée le compte (`CreationCompteHandler`
     * pour un client, tout le reste pour un exploitant), jamais par un PATCH.
     */
    #[ORM\Column(length: 16, enumType: AccountKind::class, options: ['default' => 'operator'])]
    #[Groups(['utilisateur:read', 'me:read'])]
    private AccountKind $kind = AccountKind::Operator;

    #[ORM\Column(options: ['default' => 0])]
    private int $tentativesEchouees = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verrouilleJusqua = null;

    /**
     * Réf. logique `App\Crm\Entity\Client` (M4) — lie un compte back-office/espace client (M3, non
     * spécifié dans ce dépôt) à sa fiche CRM, pour les permissions `crm.*_soi` (⚠ HYPOTHÈSE, §6/§10.8
     * plan-crm.md, à confirmer avec M8). Pas de FK dure : M4 peut anonymiser sans casser ce lien.
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['utilisateur:read', 'utilisateur:write'])]
    private ?Uuid $clientLie = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getMotDePasse(): string
    {
        return $this->motDePasse;
    }

    public function setMotDePasse(string $motDePasse): self
    {
        $this->motDePasse = $motDePasse;

        return $this;
    }

    public function getMotDePasseClair(): ?string
    {
        return $this->motDePasseClair;
    }

    public function setMotDePasseClair(?string $motDePasseClair): self
    {
        $this->motDePasseClair = $motDePasseClair;

        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->motDePasseClair = null;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getStatut(): StatutUtilisateur
    {
        return $this->statut;
    }

    public function setStatut(StatutUtilisateur $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    /**
     * Compatibilité ascendante (§2.1 plan-backoffice.md) : `isActif()`/`setActif()` restent
     * disponibles pour ne rien casser côté `VerificateurUtilisateur`, `MeController`, fixtures
     * socle/CRM déjà existantes. Non persisté — dérivé de `statut`.
     */
    #[Groups(['utilisateur:read', 'utilisateur:write', 'me:read'])]
    public function isActif(): bool
    {
        return $this->statut === StatutUtilisateur::Actif;
    }

    public function setActif(bool $actif): self
    {
        $this->statut = $actif ? StatutUtilisateur::Actif : StatutUtilisateur::Suspendu;

        return $this;
    }

    public function getJetonInvitation(): ?string
    {
        return $this->jetonInvitation;
    }

    public function setJetonInvitation(?string $jetonInvitation): self
    {
        $this->jetonInvitation = $jetonInvitation;

        return $this;
    }

    public function getJetonInvitationExpire(): ?\DateTimeImmutable
    {
        return $this->jetonInvitationExpire;
    }

    public function setJetonInvitationExpire(?\DateTimeImmutable $jetonInvitationExpire): self
    {
        $this->jetonInvitationExpire = $jetonInvitationExpire;

        return $this;
    }

    public function getDernierAcces(): ?\DateTimeImmutable
    {
        return $this->dernierAcces;
    }

    public function setDernierAcces(?\DateTimeImmutable $dernierAcces): self
    {
        $this->dernierAcces = $dernierAcces;

        return $this;
    }

    public function isMfaActif(): bool
    {
        return $this->mfaActif;
    }

    public function setMfaActif(bool $mfaActif): self
    {
        $this->mfaActif = $mfaActif;

        return $this;
    }

    public function isPlateforme(): bool
    {
        return $this->plateforme;
    }

    public function setPlateforme(bool $plateforme): self
    {
        $this->plateforme = $plateforme;

        return $this;
    }

    public function getMfaSecret(): ?string
    {
        return $this->mfaSecret;
    }

    public function setMfaSecret(?string $mfaSecret): self
    {
        $this->mfaSecret = $mfaSecret;

        return $this;
    }

    /** @return list<string>|null */
    public function getMfaCodesRecuperation(): ?array
    {
        return $this->mfaCodesRecuperation;
    }

    /** @param list<string>|null $mfaCodesRecuperation */
    public function setMfaCodesRecuperation(?array $mfaCodesRecuperation): self
    {
        $this->mfaCodesRecuperation = $mfaCodesRecuperation;

        return $this;
    }

    public function getTokenVersion(): int
    {
        return $this->tokenVersion;
    }

    public function setTokenVersion(int $tokenVersion): self
    {
        $this->tokenVersion = $tokenVersion;

        return $this;
    }

    /** @return list<string> */
    public function getRolesSecurite(): array
    {
        return $this->rolesSecurite;
    }

    /** @param list<string> $rolesSecurite */
    public function setRolesSecurite(array $rolesSecurite): self
    {
        $this->rolesSecurite = $rolesSecurite;

        return $this;
    }

    public function getTentativesEchouees(): int
    {
        return $this->tentativesEchouees;
    }

    public function setTentativesEchouees(int $tentativesEchouees): self
    {
        $this->tentativesEchouees = $tentativesEchouees;

        return $this;
    }

    public function getVerrouilleJusqua(): ?\DateTimeImmutable
    {
        return $this->verrouilleJusqua;
    }

    public function setVerrouilleJusqua(?\DateTimeImmutable $verrouilleJusqua): self
    {
        $this->verrouilleJusqua = $verrouilleJusqua;

        return $this;
    }

    public function estVerrouille(): bool
    {
        return $this->verrouilleJusqua !== null && $this->verrouilleJusqua > new \DateTimeImmutable();
    }

    public function getClientLie(): ?Uuid
    {
        return $this->clientLie;
    }

    public function setClientLie(?Uuid $clientLie): self
    {
        $this->clientLie = $clientLie;

        return $this;
    }

    // --- UserInterface / PasswordAuthenticatedUserInterface ---

    public function getPassword(): string
    {
        return $this->motDePasse;
    }

    public function getKind(): AccountKind
    {
        return $this->kind;
    }

    public function setKind(AccountKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->rolesSecurite;
        $roles[] = 'ROLE_USER';
        // La nature du compte, lisible par `security.yaml` et les expressions `is_granted`.
        $roles[] = $this->kind->role();

        return array_values(array_unique($roles));
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }
}
