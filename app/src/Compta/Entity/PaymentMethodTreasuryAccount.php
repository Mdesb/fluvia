<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le compte de trésorerie débité quand CET exploitant encaisse par CE moyen (512x banque, 531x caisse,
 * 511x valeurs à l'encaissement) — la contrepartie du compte client dans l'écriture au journal `ENC`.
 *
 * ── POURQUOI UNE ENTITÉ DE RATTACHEMENT, ET PAS UNE COLONNE SUR `MoyenPaiement` ─────────────────
 *
 * C'est la première forme que j'ai écrite, et elle était fausse. Mesuré sur la base :
 *
 *     compta_moyen_paiement      aucune colonne de rattachement   → référentiel GLOBAL
 *     compta_compte_comptable    profil_exploitant_id             → PAR EXPLOITANT
 *
 * Une colonne sur `MoyenPaiement` accroche donc un compte **par exploitant** à un objet **partagé par
 * tous** : le premier établissement qui configure « virement » impose son compte à tous les autres, et
 * leurs encaissements vont s'écrire dans SON grand livre. Violation de D3.
 *
 * ⚠ ET RIEN NE L'AURAIT SIGNALÉ. L'écriture reste équilibrée, le lettrage passe, le solde du client
 * revient à zéro. Le seul symptôme serait un grand livre étranger qui gonfle, découvert au
 * rapprochement bancaire des semaines plus tard. Six tests passaient au vert : ils n'exerçaient qu'un
 * seul profil exploitant, et un jeu de tests mono-locataire est aveugle à ce défaut par construction.
 *
 * Le patron retenu est celui de {@see MappingComptable}, unique sur `(profil_exploitant_id, categorie)` :
 * ici `(business_profile_id, payment_method_id)`.
 *
 * ── PAS D'`ApiResource` POUR L'INSTANT, ET C'EST DÉLIBÉRÉ ───────────────────────────────────────
 *
 * Le garde-fou d'écart client/serveur (n°15) est à 524 opérations inatteignables pour un plafond de
 * 524 : exposer quatre opérations qu'aucun écran n'appelle le ferait rougir le jour même. Les défauts
 * sont donc posés par migration de données, et la ressource arrivera **avec** son écran de paramètres,
 * dans le même lot — jamais avant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounting_payment_method_treasury_account')]
#[ORM\UniqueConstraint(name: 'uniq_treasury_account_profile_method', columns: ['business_profile_id', 'payment_method_id'])]
class PaymentMethodTreasuryAccount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['treasury_account:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(name: 'business_profile_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['treasury_account:read'])]
    private ?ProfilExploitant $businessProfile = null;

    #[ORM\ManyToOne(targetEntity: MoyenPaiement::class)]
    #[ORM\JoinColumn(name: 'payment_method_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['treasury_account:read'])]
    private ?MoyenPaiement $paymentMethod = null;

    #[ORM\ManyToOne(targetEntity: CompteComptable::class)]
    #[ORM\JoinColumn(name: 'treasury_account_id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    #[Groups(['treasury_account:read'])]
    private ?CompteComptable $treasuryAccount = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBusinessProfile(): ?ProfilExploitant
    {
        return $this->businessProfile;
    }

    public function setBusinessProfile(?ProfilExploitant $businessProfile): self
    {
        $this->businessProfile = $businessProfile;

        return $this;
    }

    public function getPaymentMethod(): ?MoyenPaiement
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?MoyenPaiement $paymentMethod): self
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function getTreasuryAccount(): ?CompteComptable
    {
        return $this->treasuryAccount;
    }

    public function setTreasuryAccount(?CompteComptable $treasuryAccount): self
    {
        $this->treasuryAccount = $treasuryAccount;

        return $this;
    }
}
