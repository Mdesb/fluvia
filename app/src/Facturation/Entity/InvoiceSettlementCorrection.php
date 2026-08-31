<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use App\Securite\Entity\Utilisateur;
use App\Facturation\Service\Montant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * CORRIGER LE MOYEN DE PAIEMENT D'UNE FACTURE, SANS RIEN EFFACER.
 *
 * Demandé par Maxime en ces termes : « on fait moins sur le moyen de paiement initial et plus sur le
 * nouveau moyen de paiement […] un bouton corriger le paiement, et automatiquement les écritures qui
 * vont avec ».
 *
 * ── POURQUOI UNE COMPENSATION ET NON UNE SUPPRESSION ────────────────────────────────────────────
 *
 * C'est la doctrine D45, déjà appliquée aux ventes par `App\Vente\Entity\SettlementCorrection` : une
 * correction de règlement est une écriture compensatoire **datée du jour du geste**, jamais une
 * modification de l'écriture d'origine.
 *
 * La raison est comptable et elle est simple : un règlement encaissé puis effacé laisse une caisse
 * qui ne tombe plus juste, et rien n'explique l'écart. Une contre-passation, elle, se lit — on voit
 * ce qui a été saisi, quand on s'en est aperçu, et pourquoi.
 *
 * ── DEUX LIGNES DE RÈGLEMENT, ET LE SOLDE NE BOUGE PAS ──────────────────────────────────────────
 *
 * La correction produit deux `ReglementFacture` : `−montant` sur le moyen débité, `+montant` sur le
 * moyen crédité. `Facture::getMontantRegle()` somme les règlements : leur total est donc inchangé, et
 * `getSoldeDu()` reste exact **sans qu'aucun calcul ne soit recopié ici**. C'est ce qui rend
 * l'automatisme demandé possible sans second mécanisme de lettrage.
 *
 * Les deux lignes sont conservées sur la correction : sans elles, on ne saurait pas distinguer une
 * ligne née d'une correction d'un règlement ordinaire, et un écran ne pourrait pas expliquer d'où
 * vient un montant négatif.
 *
 * ── LE MOTIF N'EST PAS DÉCORATIF ────────────────────────────────────────────────────────────────
 *
 * Ce geste déplace de l'argent entre deux moyens de paiement. Sans motif il est indéfendable en
 * contrôle — c'est la même exigence que côté vente, et pour la même raison.
 *
 * ⚠ Pas encore de `#[ApiResource]` : le cliquet d'écart refuse une opération qu'aucun écran
 * n'appelle, et il a raison. Elle s'ouvrira avec le bouton « Corriger le paiement ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_settlement_correction')]
class InvoiceSettlementCorrection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['correction_reglement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Facture::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private Facture $invoice;

    /** Code du moyen de paiement d'origine, celui qu'on retire. */
    #[ORM\Column(name: 'debited_method', length: 32)]
    #[Groups(['correction_reglement:read'])]
    private string $debitedMethod;

    /** Code du moyen de paiement réel, celui qu'on porte. */
    #[ORM\Column(name: 'credited_method', length: 32)]
    #[Groups(['correction_reglement:read'])]
    private string $creditedMethod;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['correction_reglement:read'])]
    private string $amount = Montant::ZERO;

    #[ORM\Column(length: 255)]
    #[Groups(['correction_reglement:read'])]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true)]
    #[Groups(['correction_reglement:read'])]
    private ?Utilisateur $author = null;

    /**
     * ⚠ LE JOUR DU GESTE, PAS CELUI DE LA FACTURE.
     *
     * Antidater une correction à la date du règlement d'origine reviendrait à réécrire le passé : la
     * journée comptable de cette date-là est peut-être déjà close, et l'écart qu'on corrige a bien
     * existé jusqu'à aujourd'hui.
     */
    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    #[Groups(['correction_reglement:read'])]
    private \DateTimeImmutable $occurredAt;

    /** La ligne négative produite sur le moyen d'origine. */
    #[ORM\ManyToOne(targetEntity: ReglementFacture::class)]
    #[ORM\JoinColumn(name: 'debit_entry_id', nullable: false)]
    private ReglementFacture $debitEntry;

    /** La ligne positive produite sur le moyen réel. */
    #[ORM\ManyToOne(targetEntity: ReglementFacture::class)]
    #[ORM\JoinColumn(name: 'credit_entry_id', nullable: false)]
    private ReglementFacture $creditEntry;

    /**
     * Tout est exigé à la construction : une correction à moitié posée n'a aucun sens, et une
     * propriété laissée nulle sur une colonne NOT NULL échoue au `flush()`, hors de toute validation.
     */
    public function __construct(
        Facture $invoice,
        string $debitedMethod,
        string $creditedMethod,
        string $amount,
        string $reason,
        ReglementFacture $debitEntry,
        ReglementFacture $creditEntry,
        ?Utilisateur $author,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->id = Uuid::v4();
        $this->invoice = $invoice;
        $this->debitedMethod = $debitedMethod;
        $this->creditedMethod = $creditedMethod;
        $this->amount = $amount;
        $this->reason = $reason;
        $this->debitEntry = $debitEntry;
        $this->creditEntry = $creditEntry;
        $this->author = $author;
        $this->occurredAt = $occurredAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvoice(): Facture
    {
        return $this->invoice;
    }

    public function getDebitedMethod(): string
    {
        return $this->debitedMethod;
    }

    public function getCreditedMethod(): string
    {
        return $this->creditedMethod;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getAuthor(): ?Utilisateur
    {
        return $this->author;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getDebitEntry(): ReglementFacture
    {
        return $this->debitEntry;
    }

    public function getCreditEntry(): ReglementFacture
    {
        return $this->creditEntry;
    }
}
