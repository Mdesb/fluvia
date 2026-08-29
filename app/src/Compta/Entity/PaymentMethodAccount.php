<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * « CE MOYEN DE PAIEMENT S'ENCAISSE SUR CE COMPTE » — la ventilation qui manquait.
 *
 * Demandée par Maxime dans ces termes : « on ne peut pas non plus ajouter un code comptable ici, ce
 * qui serait pratique et qu'on devra utiliser lors de la génération de certains journaux comptables ».
 *
 * ── CE QUE FAISAIT LE MOTEUR AVANT, ET POURQUOI C'ÉTAIT GÊNANT ──────────────────────────────────
 *
 * `RegimeComptableInterface::compteEncaissement()` porte encore la mention « frontière simple, hors
 * ventilation par moyen » : une vente produisait UNE ligne de débit, sur un seul compte, quel que
 * soit le mode de règlement. Espèces, carte, chèque et virement tombaient ensemble sur le 511.
 *
 * C'est précisément ce qu'un rapprochement bancaire ne peut pas exploiter. Les espèces vivent en 53,
 * les chèques à l'encaissement en 5112, la banque en 512 : les confondre oblige à refaire à la main,
 * chaque mois, la ventilation que le logiciel connaissait déjà — la vente enregistre le moyen depuis
 * toujours, et sait même le corriger (D45, `/ventes/{id}/corriger-reglement`). Seule la comptabilité
 * l'ignorait.
 *
 * ── POURQUOI PAR PROFIL EXPLOITANT, ET PAS SUR `MoyenPaiement` ──────────────────────────────────
 *
 * `MoyenPaiement` est un référentiel GLOBAL : son `code` est unique pour tout le dépôt, et il ne
 * porte pas d'établissement. Y écrire un numéro de compte le rendrait commun à tous les clients —
 * or un exploitant public tient un plan M57 et un privé un plan PCG. Le même « espèces » n'a pas le
 * même compte chez les deux, et se tromper produit des journaux faux, pas une fonctionnalité
 * absente.
 *
 * La ventilation appartient donc au PROFIL, comme `MappingComptable` (catégorie → compte de produit)
 * dont cette classe est le pendant : celle-là décrit le CRÉDIT, celle-ci le DÉBIT.
 *
 * ── UNE LIGNE PAR COUPLE, ET C'EST LA BASE QUI LE TIENT ─────────────────────────────────────────
 *
 * Deux comptes pour un même moyen chez un même exploitant n'ont pas de sens ; la contrainte le dit
 * plutôt qu'une lecture préalable, que deux requêtes simultanées franchiraient toutes les deux.
 *
 * ── AUCUNE LIGNE = COMPORTEMENT D'AVANT ─────────────────────────────────────────────────────────
 *
 * Sans ventilation déclarée, le moteur retombe sur le compte d'encaissement unique. Le déploiement
 * ne change donc rien aux écritures de personne, et la ventilation n'existe que là où quelqu'un l'a
 * demandée ET activée (`ParametresRegime::ventilationEncaissementParMoyen`, faux par défaut).
 *
 * ⚠ Pas encore de `#[ApiResource]` : exposer des opérations qu'aucun écran n'appelle referait la
 * faute qu'on passe la nuit à corriger. Les opérations s'ouvriront avec l'écran.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounting_payment_method_account')]
#[ORM\UniqueConstraint(name: 'uniq_payment_method_account', columns: ['business_profile_id', 'payment_method_id'])]
class PaymentMethodAccount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(name: 'business_profile_id', nullable: false)]
    private ProfilExploitant $businessProfile;

    /**
     * Relation réelle et non le `code` recopié : `MoyenPaiement` vit dans le même module, donc rien
     * n'oblige à passer par une référence libre. Un code renommé garde ainsi sa ventilation, là où
     * une copie du code l'aurait silencieusement orpheline.
     */
    #[ORM\ManyToOne(targetEntity: MoyenPaiement::class)]
    #[ORM\JoinColumn(name: 'payment_method_id', nullable: false)]
    private MoyenPaiement $paymentMethod;

    #[ORM\ManyToOne(targetEntity: CompteComptable::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false)]
    private CompteComptable $account;

    /**
     * Les trois valeurs sont exigées à la construction : une propriété laissée à `null` sur une
     * colonne NOT NULL passe PHP, passe Doctrine, et échoue au `flush()`, hors de toute validation.
     */
    public function __construct(ProfilExploitant $businessProfile, MoyenPaiement $paymentMethod, CompteComptable $account)
    {
        $this->id = Uuid::v4();
        $this->businessProfile = $businessProfile;
        $this->paymentMethod = $paymentMethod;
        $this->account = $account;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBusinessProfile(): ProfilExploitant
    {
        return $this->businessProfile;
    }

    public function getPaymentMethod(): MoyenPaiement
    {
        return $this->paymentMethod;
    }

    public function getAccount(): CompteComptable
    {
        return $this->account;
    }

    public function setAccount(CompteComptable $account): self
    {
        $this->account = $account;

        return $this;
    }
}
