<?php

declare(strict_types=1);

namespace App\Recouvrement\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\State\GrantBlockingExemptionProcessor;
use App\Recouvrement\State\RevokeBlockingExemptionProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Ce redevable n'est jamais bloqué » — l'exemption durable du blocage d'accès pour impayé.
 *
 * Maxime, 30/08 : la collectivité qui produit un impayé par mois et qu'on ne veut jamais bloquer.
 * Aujourd'hui chaque impayé se force à la main, un par un.
 *
 * ── ELLE PORTE SUR LE REDEVABLE, PAS SUR LE DOSSIER — ET C'EST TOUTE LA DIFFÉRENCE ─────────────
 *
 * `IncidentImpaye::accesBloque` se pose **par dossier** ; la porte se ferme **par redevable**.
 * Forcer la réouverture agit sur un dossier : le mois suivant, un nouvel incident referme la porte
 * et il faut recommencer. Une exemption qui porterait elle aussi sur le dossier serait donc un
 * forçage renommé, pas une exemption.
 *
 * Le couple `debtorType`/`debtorRef` reprend exactement l'identification opaque d'`IncidentImpaye`
 * (`typeRedevable`/`referenceRedevable`) : le recouvrement ne connaît pas la nature du redevable —
 * client, abonné fitness, structure — il en connaît la référence.
 *
 * ── ⚠ ELLE N'A PAS DE DATE DE FIN, ET C'EST UN CHOIX EXPLICITE ────────────────────────────────
 *
 * Une date de fin obligatoire a été proposée et écartée par Maxime, pour une raison qui vaut d'être
 * gardée ici : **une exemption qui expire un lundi matin bloque un client à la porte sans que
 * personne n'ait rien décidé ce jour-là.** Le retrait doit être un geste, comme la pose.
 *
 * ── ⚠ LE MOTIF EST OBLIGATOIRE PARCE QU'IL EST LA SEULE GARDE ─────────────────────────────────
 *
 * Le droit exigé est `recouvrement.forcer_acces`, le même que le forçage — choix de Maxime, contre
 * la proposition d'un droit dédié. La contrepartie était énoncée avant : **un agent de caisse peut
 * exempter un client pour toujours.** Il n'y a donc pas de second verrou ; le motif et la trace de
 * l'agent sont tout ce qui reste pour qu'on sache, dans six mois, pourquoi ce client ne bloque
 * jamais. D'où `NotBlank` et une colonne non nulle plutôt qu'un champ facultatif.
 *
 * ── LE RETRAIT NE SUPPRIME PAS LA LIGNE ────────────────────────────────────────────────────────
 *
 * `revokedAt` bascule l'exemption au lieu de l'effacer. Supprimer perdrait la réponse à « qui avait
 * exempté ce client, et pourquoi, avant qu'on ne le rebloque ». C'est la même raison qui rend le
 * motif obligatoire à la pose : l'exemption est une décision, et une décision se relit.
 *
 * ⚠ **Aucune contrainte d'unicité en base**, et ce n'est pas un oubli : « une seule exemption ACTIVE
 * par redevable » est un index partiel, que MariaDB ne connaît pas. La règle est donc tenue par
 * `BlockingExemptionRegistry` — et c'est précisément pour cela qu'elle y est écrite une seule fois.
 */
#[ApiResource(
    shortName: 'BlockingExemption',
    normalizationContext: ['groups' => ['blocking_exemption:read']],
    // ⚠ GROUPE D'ÉCRITURE VIDE, ET C'EST VOLONTAIRE (D41).
    //
    // Sans `denormalizationContext`, API Platform rend écrivable toute propriété munie d'un
    // mutateur — `etablissement` compris : l'appelant choisirait à quel établissement appartient
    // l'exemption, c'est-à-dire chez qui elle est lisible et retirable.
    //
    // Aucune opération d'ici ne désérialise (toutes déclarent `input: false` et lisent le corps
    // dans leur processeur), donc rien n'était exploitable. Mais la déclaration disait le contraire
    // de l'intention, et c'est elle qui accueillera la prochaine opération ajoutée ici.
    denormalizationContext: ['groups' => ['blocking_exemption:write']],
    operations: [
        // Voir qui est exempte releve du PILOTAGE, pas du pouvoir d'exempter : celui qui suit les
        // impayes doit pouvoir expliquer pourquoi un client ne bloque jamais.
        new GetCollection(
            uriTemplate: '/recouvrement/exemptions',
            security: "is_granted('PERM', 'recouvrement.piloter')",
        ),
        // ⚠ `input: false` : le corps est lu par le processeur, pas desérialisé dans l'entité.
        // Sans ça, API Platform construit un `BlockingExemption` vide, le valide AVANT le processeur,
        // et rend un 422 qui parle de `debtorType` — un nom de colonne que l'appelant n'a jamais
        // employé. Le refus doit nommer ce que l'utilisateur a écrit, pas ce que la base attend.
        // ⚠ CHEMIN SUFFIXE D'UN VERBE, comme `/incidents/{id}/resoudre` juste a cote — par
        // convention du module, et NON par contrainte de mesure.
        //
        // J'avais d'abord ecrit ici que la mesure d'ecart client/serveur indexait les appels par
        // CHEMIN, et que deux operations sur un meme chemin n'en rendaient donc qu'une atteignable.
        // C'etait faux, et allaccess-73 l'a mesure : elle indexe bien par (chemin, METHODE). Ce qui
        // echouait etait la DETECTION de la methode, qui ne lisait que le reste de la ligne de
        // l'appel — mon `method: 'POST'` etant a la ligne suivante, il retombait sur le defaut GET
        // et se confondait avec le GET du meme chemin. 107 appels sur 454 etaient dans ce cas.
        //
        // Corrige depuis, plafond passe de 685 a 684. Un GET et un POST canoniques sur un meme
        // chemin sont donc parfaitement mesurables : si vous en avez besoin, ne renoncez pas sur la
        // foi de ce qui etait ecrit ici. Ce chemin-ci reste parce qu'il est plus lisible.
        new Post(
            uriTemplate: '/recouvrement/exemptions/accorder',
            read: false,
            input: false,
            security: "is_granted('PERM', 'recouvrement.forcer_acces')",
            processor: GrantBlockingExemptionProcessor::class,
        ),
        // ⚠ `read: true` : le retrait porte sur une exemption existante, donc l'operation la charge
        // — c'est aussi ce qui fait passer l'objet au cloisonnement avant qu'on n'y touche.
        new Post(
            uriTemplate: '/recouvrement/exemptions/{id}/retirer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'recouvrement.forcer_acces')",
            processor: RevokeBlockingExemptionProcessor::class,
        ),
    ],
)]
#[ORM\Entity]
#[ORM\Table(name: 'recovery_blocking_exemption')]
#[ORM\Index(name: 'idx_blocking_exemption_debtor', columns: ['debtor_type', 'debtor_ref'])]
class BlockingExemption
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['blocking_exemption:read'])]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Groups(['blocking_exemption:read'])]
    private string $debtorType = '';

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Groups(['blocking_exemption:read'])]
    private string $debtorRef = '';

    /**
     * ⚠ CE CHAMP S'APPELLE `etablissement` EN FRANÇAIS, ET C'EST OBLIGATOIRE.
     *
     * `PerimetreRecouvrementExtension` filtre avec `IDENTITY(%s.etablissement)`, écrit en dur. Un
     * champ nommé `establishment` n'est pas vu par le cloisonnement : la collection deviendrait
     * lisible d'un établissement à l'autre, sans erreur ni message. Le nom porte la sécurité.
     *
     * ⚠ ET IL FAUT AUSSI INSCRIRE L'ENTITÉ DANS `PerimetreRecouvrementExtension::CHAINES`. Ni l'un
     * ni l'autre ne suffit seul : sans le champ, la DQL porterait sur une propriété inexistante ;
     * sans l'inscription, l'extension ignore la classe et ne filtre rien du tout — c'est ce second
     * cas qui est dangereux, parce qu'il ne produit aucune erreur.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    /** ⚠ Non nullable : c'est la seule garde d'une décision qui n'a pas de second verrou. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: "Le motif est obligatoire : une exemption durable sans raison écrite est indéfendable dans six mois.")]
    #[Groups(['blocking_exemption:read'])]
    private string $reason = '';

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $grantedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['blocking_exemption:read'])]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['blocking_exemption:read'])]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $revokedBy = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }

    public function getDebtorType(): string
    {
        return $this->debtorType;
    }

    public function setDebtorType(string $debtorType): self
    {
        $this->debtorType = $debtorType;

        return $this;
    }

    public function getDebtorRef(): string
    {
        return $this->debtorRef;
    }

    public function setDebtorRef(string $debtorRef): self
    {
        $this->debtorRef = $debtorRef;

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

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getGrantedBy(): ?Utilisateur
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(?Utilisateur $grantedBy): self
    {
        $this->grantedBy = $grantedBy;

        return $this;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevokedBy(): ?Utilisateur
    {
        return $this->revokedBy;
    }

    public function revoke(?Utilisateur $agent): self
    {
        $this->revokedAt = new \DateTimeImmutable();
        $this->revokedBy = $agent;

        return $this;
    }
}
