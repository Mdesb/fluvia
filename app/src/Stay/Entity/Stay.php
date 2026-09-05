<?php

declare(strict_types=1);

namespace App\Stay\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Stay\Enum\StayStatus;
use App\Stay\State\AddStayChargeProcessor;
use App\Stay\State\CloseStayProcessor;
use App\Stay\State\OpenStayProcessor;
use App\Stay\State\SettleStayProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Le séjour (ACT-3, D16) : **un client, une période, un seul compte**. Tout ce qu'il consomme sur
 * place — emplacement, entrées piscine, additions du bar, parties de bowling — s'y rattache et se
 * règle une fois, au départ. C'est le concept qui transforme « six modules » en « un logiciel ».
 *
 * **Ce qu'un séjour n'est pas.** Ce n'est pas une réservation : réserver reste l'acte unique et
 * paramétré de D16, porté par `App\Reservation`. Un séjour est la **conséquence** d'un ou plusieurs
 * actes de ce genre, et il survit à chacun d'eux — un client peut arriver sans réservation, ou en
 * cumuler quatre. C'est aussi pourquoi ce module n'appelle jamais `App\Reservation` directement
 * (D2) : il écoute des faits.
 *
 * **Le cloisonnement de cette ressource tient a deux mecanismes, pas un.**
 * `App\Stay\Doctrine\StayScopeExtension` restreint la requete elle-meme : c'est le seul rempart
 * d'un `GetCollection`, qui ne traverse aucun processor. `App\Stay\Security\StayScopeGuard` protege
 * les ecritures et les entites resolues depuis le corps de la requete (D8). Retirer l'un des deux
 * laisse une moitie de la surface ouverte.
 *
 * **Le total n'est pas stocké.** Le solde se dérive des lignes (`StayCharge`), il n'est pas
 * dénormalisé ici. Un compteur entretenu à la main dérive dès la première ligne annulée, corrigée ou
 * rejouée, et un séjour dont le total ment est pire qu'un séjour lent à calculer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stay_stay')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'IDX_STAY_ETAB_STATUS')]
#[ORM\Index(columns: ['establishment_id', 'arrival_date'], name: 'IDX_STAY_ETAB_ARRIVAL')]
// Index de cle etrangere declare explicitement : sans lui, Doctrine en genere un au nom calcule
// et `migrations:diff` propose un renommage dans le lot de CHAQUE session (D32). La dette
// d'index Stay signalee le 24/08 vient de la, et elle s'arrete ici.
#[ORM\Index(columns: ['customer_id'], name: 'IDX_STAY_CUSTOMER')]
#[ORM\UniqueConstraint(name: 'UNIQ_STAY_ETAB_REFERENCE', columns: ['establishment_id', 'reference'])]
#[ApiResource(
    shortName: 'Stay',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'stay.read')"),
        new Get(security: "is_granted('PERM', 'stay.read')"),
        // `input: false` + corps lu dans le processor : idiome du depot (aucun precedent de DTO
        // d'entree auto-deserialise), cf. `App\Vente\Service\LecteurCorps`.
        new Post(
            uriTemplate: '/stays',
            security: "is_granted('PERM', 'stay.write')",
            read: false,
            input: false,
            processor: OpenStayProcessor::class,
        ),
        new Post(
            uriTemplate: '/stays/{id}/charges',
            security: "is_granted('PERM', 'stay.charge')",
            read: false,
            input: false,
            processor: AddStayChargeProcessor::class,
        ),
        new Post(
            uriTemplate: '/stays/{id}/close',
            security: "is_granted('PERM', 'stay.write')",
            read: false,
            input: false,
            processor: CloseStayProcessor::class,
        ),
        new Post(
            uriTemplate: '/stays/{id}/settle',
            security: "is_granted('PERM', 'stay.settle')",
            read: false,
            input: false,
            processor: SettleStayProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['stay:read']],
)]
class Stay
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['stay:read'])]
    private Uuid $id;

    /**
     * Périmètre du séjour. **Immuable après création** : déplacer un séjour d'un établissement à
     * l'autre déplacerait avec lui des lignes déjà encaissées ailleurs. Aucun setter n'est exposé.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Etablissement $establishment;

    /**
     * Le titulaire du compte. Non nullable : un séjour anonyme n'a pas de destinataire au moment de
     * régler, et le porte-monnaie comme le contrôle d'accès sont déjà rattachés au client.
     */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    // ⚠ SANS CE GROUPE, AUCUNE LECTURE NE DISAIT DE QUI ETAIT LE SEJOUR. Le `POST` exige un client,
    // et plus rien ensuite ne le nommait : ni la collection, ni l'item, ni la note. L'ecran ne
    // pouvait afficher que la reference `SEJ-...`, ce que le modele annonce comme « affichee au
    // comptoir » — vrai pour appeler quelqu'un, faux pour savoir qui c'est.
    //
    // Les champs d'identite de `Client` portent `stay:read` en retour, comme ils portent deja
    // `beneficiaire:read` pour la meme raison exactement.
    #[Groups(['stay:read'])]
    private Client $customer;

    /** Référence lisible par l'exploitant, unique par établissement (affichée au comptoir). */
    #[ORM\Column(length: 32)]
    #[Groups(['stay:read'])]
    private string $reference;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['stay:read'])]
    private \DateTimeImmutable $arrivalDate;

    /**
     * Date de départ **prévue**. Nullable : un camping accepte des séjours ouverts, et forcer une date
     * inventée obligerait à la corriger tous les matins. Le départ réel est `closedAt`.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['stay:read'])]
    private ?\DateTimeImmutable $expectedDepartureDate = null;

    // `options: default` est declare ici parce que la migration pose bien un DEFAULT en base, et
    // qu'un mapping qui l'ignore fait proposer un CHANGE a chaque `schema:update --complete` —
    // c'est-a-dire dans le diff de toutes les sessions (D32). Le DEFAULT SQL est voulu : une ligne
    // inseree hors ORM (reprise, correctif manuel) nait ouverte plutot qu'avec un statut vide.
    #[ORM\Column(length: 16, enumType: StayStatus::class, options: ['default' => 'open'])]
    #[Groups(['stay:read'])]
    private StayStatus $status = StayStatus::Open;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['stay:read'])]
    private \DateTimeImmutable $openedAt;

    /** Départ réel. Renseigné au passage en `Closed`, jamais avant. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['stay:read'])]
    private ?\DateTimeImmutable $closedAt = null;

    /** Règlement du solde. Peut être postérieur à `closedAt` — voir `StayStatus`. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['stay:read'])]
    private ?\DateTimeImmutable $settledAt = null;

    public function __construct(
        Etablissement $establishment,
        Client $customer,
        string $reference,
        \DateTimeImmutable $arrivalDate,
        \DateTimeImmutable $openedAt,
    ) {
        $this->id = Uuid::v7();
        $this->establishment = $establishment;
        $this->customer = $customer;
        $this->reference = $reference;
        $this->arrivalDate = $arrivalDate;
        $this->openedAt = $openedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): Etablissement
    {
        return $this->establishment;
    }

    public function getCustomer(): Client
    {
        return $this->customer;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getArrivalDate(): \DateTimeImmutable
    {
        return $this->arrivalDate;
    }

    public function getExpectedDepartureDate(): ?\DateTimeImmutable
    {
        return $this->expectedDepartureDate;
    }

    public function setExpectedDepartureDate(?\DateTimeImmutable $expectedDepartureDate): self
    {
        $this->expectedDepartureDate = $expectedDepartureDate;

        return $this;
    }

    public function getStatus(): StayStatus
    {
        return $this->status;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getSettledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }

    /** Un séjour clos ou soldé n'accepte plus de ligne — c'est l'invariant que `StayCharge` suppose. */
    public function acceptsCharges(): bool
    {
        return StayStatus::Open === $this->status;
    }

    /**
     * Départ du client. Idempotent volontairement : le comptoir clôture parfois deux fois, et lever
     * une exception sur le second appel transformerait une maladresse en incident.
     */
    public function close(\DateTimeImmutable $closedAt): self
    {
        if (StayStatus::Open === $this->status) {
            $this->status = StayStatus::Closed;
            $this->closedAt = $closedAt;
        }

        return $this;
    }

    /**
     * Solde réglé. Un séjour encore ouvert ne peut pas être soldé : il accepterait une ligne juste
     * après, et le « réglé une fois » de D16 serait faux.
     */
    public function settle(\DateTimeImmutable $settledAt): self
    {
        if (StayStatus::Closed !== $this->status) {
            throw new \LogicException(sprintf(
                'Un séjour doit être clos avant d\'être soldé (état courant : %s).',
                $this->status->value,
            ));
        }

        $this->status = StayStatus::Settled;
        $this->settledAt = $settledAt;

        return $this;
    }
}
