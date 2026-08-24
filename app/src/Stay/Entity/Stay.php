<?php

declare(strict_types=1);

namespace App\Stay\Entity;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Stay\Enum\StayStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
 * **Pas encore d'`ApiResource`, volontairement.** Exposer une collection avant d'avoir son provider
 * cloisonné reviendrait à publier un IDOR — précisément ce que D3/D8 et le garde-fou de cloisonnement
 * interdisent. La surface API arrive au lot suivant, avec son provider et ses tests de non-régression
 * de périmètre.
 *
 * **Le total n'est pas stocké.** Le solde se dérive des lignes (`StayCharge`), il n'est pas
 * dénormalisé ici. Un compteur entretenu à la main dérive dès la première ligne annulée, corrigée ou
 * rejouée, et un séjour dont le total ment est pire qu'un séjour lent à calculer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stay_stay')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'IDX_STAY_ETAB_STATUS')]
#[ORM\Index(columns: ['establishment_id', 'arrival_date'], name: 'IDX_STAY_ETAB_ARRIVAL')]
#[ORM\UniqueConstraint(name: 'UNIQ_STAY_ETAB_REFERENCE', columns: ['establishment_id', 'reference'])]
class Stay
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
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
    private Client $customer;

    /** Référence lisible par l'exploitant, unique par établissement (affichée au comptoir). */
    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $arrivalDate;

    /**
     * Date de départ **prévue**. Nullable : un camping accepte des séjours ouverts, et forcer une date
     * inventée obligerait à la corriger tous les matins. Le départ réel est `closedAt`.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expectedDepartureDate = null;

    #[ORM\Column(length: 16, enumType: StayStatus::class)]
    private StayStatus $status = StayStatus::Open;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $openedAt;

    /** Départ réel. Renseigné au passage en `Closed`, jamais avant. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** Règlement du solde. Peut être postérieur à `closedAt` — voir `StayStatus`. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
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
