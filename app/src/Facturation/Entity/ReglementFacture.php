<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use App\Compta\Entity\EcritureComptable;
use App\Facturation\Service\Montant;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Règlement enregistré sur une facture directe (RG-FACT-06, `plan-facturation.md` §1.5).
 *
 * Addition technique assumée par le plan : `App\Compta\Entity\LettrageEcriture` (M6) ne porte pas
 * de montant — c'est un marqueur « ligne soldée », tout ou rien. Facturation trace donc ses
 * règlements **partiels** dans son propre domaine et n'appelle `LettrageHandler::lettrer()`
 * **qu'une seule fois**, quand le cumul atteint le total TTC. Aucun second mécanisme de lettrage
 * n'est créé : la réconciliation comptable finale reste portée par M6.
 */
#[ORM\Entity]
#[ORM\Table(name: 'facturation_reglement')]
class ReglementFacture
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['facture:read', 'reglement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Facture::class, inversedBy: 'reglements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Facture $facture = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Groups(['facture:read', 'reglement:read'])]
    private string $montant = Montant::ZERO;

    /** Code du moyen de paiement (référentiel M6 réutilisé en clair, cf. `Paiement::$moyenCode`). */
    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Groups(['facture:read', 'reglement:read'])]
    private string $moyen = 'virement';

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['facture:read', 'reglement:read'])]
    private ?string $reference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['facture:read', 'reglement:read'])]
    private \DateTimeImmutable $dateReglement;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reglement:read'])]
    private ?Utilisateur $auteur = null;

    /**
     * L'écriture d'encaissement produite par ce règlement (journal `ENC`), miroir exact de
     * `App\Finance\SupplierInvoice\Entity\SupplierPayment::$ledgerEntry`.
     *
     * ⚠ CE N'EST PAS UN CONFORT DE TRAÇABILITÉ, C'EST CE QUI REND LE LETTRAGE POSSIBLE.
     * `LettrageHandler::lettrerGroupe()` exige Σdébit = Σcrédit sur les lignes qu'on lui passe. Une
     * facture réglée en deux fois produit DEUX écritures d'encaissement : au moment de solder, il faut
     * rassembler la ligne 411 débitrice de la facture ET les lignes 411 créditrices de tous les
     * règlements — sans quoi la somme ne tombe pas et le lettrage lève. Le chemin fournisseur a
     * rencontré ce défaut avant nous (« Défaut 5 ») et le résout de la même manière.
     *
     * Nullable : les règlements enregistrés avant ce lot n'ont produit aucune écriture. Ils ne
     * peuvent pas non plus en produire après coup — leur facture est déjà `payee`, et le handler
     * refuse tout règlement sur une facture qui ne soit pas en attente ou partiellement réglée.
     */
    #[ORM\ManyToOne(targetEntity: EcritureComptable::class)]
    #[ORM\JoinColumn(name: 'ecriture_encaissement_id', nullable: true)]
    #[Groups(['reglement:read'])]
    private ?EcritureComptable $ecritureEncaissement = null;

    /** Code de rapprochement partagé par les lignes lettrées ensemble, quand la facture est soldée. */
    #[ORM\Column(name: 'reconciliation_code', length: 36, nullable: true)]
    #[Groups(['reglement:read'])]
    private ?string $reconciliationCode = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateReglement = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFacture(): ?Facture
    {
        return $this->facture;
    }

    public function setFacture(?Facture $facture): self
    {
        $this->facture = $facture;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = Montant::normaliser($montant);

        return $this;
    }

    public function getMoyen(): string
    {
        return $this->moyen;
    }

    public function setMoyen(string $moyen): self
    {
        $this->moyen = $moyen;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getDateReglement(): \DateTimeImmutable
    {
        return $this->dateReglement;
    }

    public function setDateReglement(\DateTimeImmutable $dateReglement): self
    {
        $this->dateReglement = $dateReglement;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getEcritureEncaissement(): ?EcritureComptable
    {
        return $this->ecritureEncaissement;
    }

    public function setEcritureEncaissement(?EcritureComptable $ecritureEncaissement): self
    {
        $this->ecritureEncaissement = $ecritureEncaissement;

        return $this;
    }

    public function getReconciliationCode(): ?string
    {
        return $this->reconciliationCode;
    }

    public function setReconciliationCode(?string $reconciliationCode): self
    {
        $this->reconciliationCode = $reconciliationCode;

        return $this;
    }
}
