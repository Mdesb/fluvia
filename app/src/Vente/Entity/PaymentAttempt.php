<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use App\Securite\Entity\Utilisateur;
use App\Vente\Enum\PaymentAttemptStatus;
use App\Vente\Enum\StatutTPE;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une tentative de règlement, écrite et validée en base AVANT que l'argent bouge (G-3, G-6 du ticket
 * opposable). Si le processus meurt pendant l'appel au terminal, elle reste : un rejeu sait qu'une
 * demande est partie sans issue connue, et ne repart pas au terminal.
 *
 * ── DEUX UNICITÉS, ET C'EST TOUT LE MÉCANISME ─────────────────────────────────────────────────
 *
 * - `idempotency_key` : une tentative par clé. Jamais deux sollicitations du terminal ou du
 *   porte-monnaie pour une même clé.
 * - `open_sale_id` : la vente, tant que la tentative est `pending` ou `unresolved` ; nul ensuite.
 *   Une seule tentative ouverte par vente : la seconde demande reçoit « en cours ». C'est une
 *   contrainte et un statut, PAS un verrou de base tenu pendant l'appel au terminal (C-9).
 *   Plusieurs `NULL` passent l'index unique : mesuré, `PaymentAttemptSchemaTest`.
 *
 * ── ÉCRITE ET LUE EN SQL, JAMAIS PAR L'ENTITYMANAGER ────────────────────────────────────────────
 *
 * `PaymentAttemptStore` l'écrit et la fait passer d'un statut à l'autre par des `UPDATE … WHERE
 * status = <attendu>` (DBAL). Deux raisons : la tentative doit être validée AVANT l'effet, hors de
 * l'unité de travail du règlement ; et elle doit pouvoir se clore après un `flush()` en échec, quand
 * l'`EntityManager` est fermé. Cette classe déclare le schéma (harnais de test, migration).
 *
 * Non exposée en API. Les champs de déclaration (`declaredBy`, `declaredAt`, `cardReference`) sont
 * posés par la déclaration du caissier, au lot 3.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sale_payment_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_payment_attempt_key', columns: ['idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_attempt_open_sale', columns: ['open_sale_id'])]
class PaymentAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(name: 'sale_id', nullable: false)]
    private Vente $sale;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(name: 'open_sale_id', nullable: true)]
    private ?Vente $openSale = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $idempotencyKey;

    #[ORM\Column(length: 32)]
    private string $paymentMethodCode;

    /** Le montant du corps de la demande ; nul s'il était absent (« le reste dû »). Juge le rejeu (G-4). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $requestedAmount = null;

    /** Le montant de l'effet, calculé sur la vente relue une fois tenue : celui qu'on envoie au terminal. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column]
    private bool $usesTerminal;

    #[ORM\Column(length: 24, enumType: PaymentAttemptStatus::class)]
    private PaymentAttemptStatus $status;

    #[ORM\Column(length: 12, nullable: true, enumType: StatutTPE::class)]
    private ?StatutTPE $terminalStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\ManyToOne(targetEntity: Paiement::class)]
    #[ORM\JoinColumn(name: 'payment_id', nullable: true)]
    private ?Paiement $payment = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'declared_by_id', nullable: true)]
    private ?Utilisateur $declaredBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $declaredAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $cardReference = null;
}
