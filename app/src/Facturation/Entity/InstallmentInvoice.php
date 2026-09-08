<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Cette échéance a déjà donné lieu à une facture. » — le registre qui empêche d'en émettre deux.
 *
 * ── CE QU'IL EST, ET CE QU'IL N'EST PAS ─────────────────────────────────────────────────────────
 *
 * Ce n'est pas une facture, ni une copie de facture : c'est un **verrou nommé**. Il porte la
 * référence opaque de l'échéance d'origine (`EcheanceSepaDue::$referenceOrigine`, l'identifiant que
 * la verticale a choisi) et l'identifiant de la facture qui en est née.
 *
 * ⚠ LA GARANTIE EST LA CONTRAINTE D'UNICITÉ EN BASE, PAS LA LECTURE QUI LA PRÉCÈDE.
 * `InstallmentInvoicer` cherche d'abord une ligne existante — mais entre cette lecture et l'écriture,
 * un second appel peut passer : un ordonnanceur qui repasse, une relance manuelle après un doute,
 * deux exploitants qui cliquent. Seule `uniq_installment_invoice_origin` tranche, et le perdant
 * rattrape la `UniqueConstraintViolationException` pour rendre la facture du gagnant. C'est
 * exactement le patron de {@see \App\Subscription\Entity\SubscriptionInvoice}, éprouvé sur la
 * facturation SaaS.
 *
 * ⚠ ET LE VERROU SE POSE **AVANT** L'ÉMISSION, PAS APRÈS. Une facture émise puis enregistrée
 * laisserait une fenêtre où deux documents scellés existent déjà — et un document scellé ne
 * s'annule pas, il s'avoire. Si l'émission échoue ensuite, c'est la RÉSERVATION qu'on retire, pour
 * que l'échéance reste rejouable au lieu de paraître « déjà facturée » à cause d'une panne technique.
 *
 * ── POURQUOI L'ÉTABLISSEMENT EST ICI ────────────────────────────────────────────────────────────
 *
 * Pour le cloisonnement : l'entité entre dans
 * `PerimetreFacturationExtension::RESOURCES_ETABLISSEMENT_DIRECT`. Une entité rattachable absente de
 * la liste blanche de son module est une fuite silencieuse — l'oubli a déjà été commis trois fois
 * (`CardRejection`, `DailyClosure`, `OperationScellee`), et le garde-fou n°35 existe pour ça.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_installment_invoice')]
#[ORM\UniqueConstraint(name: 'uniq_installment_invoice_origin', columns: ['origin_reference'])]
class InstallmentInvoice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['installment_invoice:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['installment_invoice:read'])]
    private ?Etablissement $etablissement = null;

    /**
     * L'identifiant de l'échéance d'origine, tel que la verticale l'a choisi — opaque ici, et c'est
     * volontaire : `Facturation` ne connaît aucun type métier de Sport, Réservation ou SEPA.
     *
     * 64 caractères comme `LigneRemiseSepa::$referenceOrigine` et
     * `IncidentImpaye::$referenceEcheanceOrigine`, qui portent la même valeur : trois colonnes qui se
     * comparent doivent avoir la même largeur, sinon la troncature silencieuse fait diverger ce qui
     * devrait être égal.
     */
    #[ORM\Column(name: 'origin_reference', length: 64)]
    #[Assert\NotBlank]
    #[Groups(['installment_invoice:read'])]
    private string $originReference = '';

    #[ORM\Column(name: 'invoice_id', type: UuidType::NAME, nullable: true)]
    #[Groups(['installment_invoice:read'])]
    private ?Uuid $invoiceId = null;

    #[ORM\Column(name: 'issued_at', type: 'datetime_immutable')]
    #[Groups(['installment_invoice:read'])]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'total_cents', options: ['default' => 0])]
    #[Groups(['installment_invoice:read'])]
    private int $totalCents = 0;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->issuedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getOriginReference(): string
    {
        return $this->originReference;
    }

    public function setOriginReference(string $originReference): self
    {
        $this->originReference = $originReference;

        return $this;
    }

    public function getInvoiceId(): ?Uuid
    {
        return $this->invoiceId;
    }

    public function setInvoiceId(?Uuid $invoiceId): self
    {
        $this->invoiceId = $invoiceId;

        return $this;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(\DateTimeImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function setTotalCents(int $totalCents): self
    {
        $this->totalCents = $totalCents;

        return $this;
    }
}
