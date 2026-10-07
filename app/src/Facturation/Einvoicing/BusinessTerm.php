<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

/**
 * LES TERMES MÉTIER D'EN 16931 QUE NOTRE FACTURE DOIT PORTER.
 *
 * EN 16931 est le **modèle sémantique** européen de la facture électronique. Il ne dit pas comment
 * écrire le fichier — ça, ce sont les syntaxes (UBL 2.1, UN/CEFACT CII) — il dit **quelles
 * informations** une facture doit contenir, chacune identifiée par un code `BT-xx`.
 *
 * ⚠ **C'EST LA PARTIE COMMUNE À QUATRE PAYS AU MOINS.** La France (Factur-X / Chorus / PDP),
 * l'Allemagne (XRechnung), l'Italie (SdI) et l'Espagne reposent tous dessus. Les plateformes
 * nationales sont des **transports** par-dessus le même modèle, avec chacune ses restrictions
 * (CIUS). Construire le modèle une fois sert les quatre ; construire un connecteur national d'abord
 * ne sert qu'un.
 *
 * L'Italie a bien un CIUS : le **CIUS-IT** (AgID et Agenzia delle Entrate), avec son schematron
 * pour UBL et pour CII. Il vaut pour les factures EN 16931 adressées à l'administration publique
 * via le SdI, qui le vérifie puis traduit la facture en **FatturaPA**. FatturaPA est le format
 * national (DM 55/2013), pas une syntaxe EN 16931 : en émettre directement serait une conversion.
 * Sources : AgID, « CIUS-IT » rel. 1.0.0 (eIGOR, 2017) ; provvedimento de l'Agenzia delle Entrate
 * du 18/04/2019 ; Regole tecniche (d.lgs. 148/2018, art. 3 c. 1) v2.6 du 15/05/2025, §§ 1 à 4.
 *
 * ── CE QUE CETTE ÉNUMÉRATION EST, ET CE QU'ELLE N'EST PAS ────────────────────────────────────────
 *
 * Elle liste les termes **obligatoires** dont on a besoin pour émettre. Elle n'est pas la norme :
 * EN 16931 compte des centaines de termes et une centaine de règles métier (`BR-xx`) que seul un
 * validateur officiel (schematron) peut vérifier.
 *
 * ⚠ **Porter tous ces termes ne rend donc PAS conforme.** Ça rend *émettable*. La conformité se
 * prouve contre le schematron de la norme et celui de chaque CIUS national — ce qui reste à faire,
 * et ce qu'il ne faut pas laisser croire entre-temps.
 */
enum BusinessTerm: string
{
    // ── En-tête ─────────────────────────────────────────────────────────────────────────────────
    case InvoiceNumber = 'BT-1';
    case IssueDate = 'BT-2';
    case TypeCode = 'BT-3';
    case CurrencyCode = 'BT-5';

    // ── Vendeur ─────────────────────────────────────────────────────────────────────────────────
    case SellerName = 'BT-27';
    case SellerVatIdentifier = 'BT-31';
    case SellerLegalIdentifier = 'BT-30';
    case SellerStreet = 'BT-35';
    case SellerCity = 'BT-37';
    case SellerPostcode = 'BT-38';
    case SellerCountryCode = 'BT-40';

    // ── Acheteur ────────────────────────────────────────────────────────────────────────────────
    case BuyerName = 'BT-44';
    case BuyerStreet = 'BT-50';
    case BuyerCity = 'BT-52';
    case BuyerPostcode = 'BT-53';
    case BuyerCountryCode = 'BT-55';

    // ── Totaux ──────────────────────────────────────────────────────────────────────────────────
    case SumOfLineNetAmounts = 'BT-106';
    case TotalWithoutVat = 'BT-109';
    case TotalVatAmount = 'BT-110';
    case TotalWithVat = 'BT-112';
    case AmountDueForPayment = 'BT-115';

    // ── Ligne ───────────────────────────────────────────────────────────────────────────────────
    case LineIdentifier = 'BT-126';
    case LineQuantity = 'BT-129';
    case LineUnitCode = 'BT-130';
    case LineNetAmount = 'BT-131';
    case ItemName = 'BT-153';
    case LineVatCategoryCode = 'BT-151';
    case LineVatRate = 'BT-152';

    /**
     * Ce que le terme désigne, en français, pour un message d'erreur qu'un exploitant peut lire.
     *
     * ⚠ Un code `BT-31` seul ne dit rien à personne. Un contrôle qui refuse une facture doit nommer
     * ce qui manque dans les mots de celui qui doit le fournir — pas dans ceux de la norme.
     */
    public function libelle(): string
    {
        return match ($this) {
            self::InvoiceNumber => 'le numéro de facture',
            self::IssueDate => 'la date d’émission',
            self::TypeCode => 'la nature du document (facture ou avoir)',
            self::CurrencyCode => 'la devise',
            self::SellerName => 'la raison sociale du vendeur',
            self::SellerVatIdentifier => 'le numéro de TVA intracommunautaire du vendeur',
            self::SellerLegalIdentifier => 'l’identifiant légal du vendeur (SIREN/SIRET)',
            self::SellerStreet => 'la rue du vendeur',
            self::SellerCity => 'la ville du vendeur',
            self::SellerPostcode => 'le code postal du vendeur',
            self::SellerCountryCode => 'le pays du vendeur',
            self::BuyerName => 'le nom ou la raison sociale de l’acheteur',
            self::BuyerStreet => 'la rue de l’acheteur',
            self::BuyerCity => 'la ville de l’acheteur',
            self::BuyerPostcode => 'le code postal de l’acheteur',
            self::BuyerCountryCode => 'le pays de l’acheteur',
            self::SumOfLineNetAmounts => 'la somme des lignes hors taxes',
            self::TotalWithoutVat => 'le total hors taxes',
            self::TotalVatAmount => 'le montant total de TVA',
            self::TotalWithVat => 'le total toutes taxes comprises',
            self::AmountDueForPayment => 'le montant restant dû',
            self::LineIdentifier => 'l’identifiant de ligne',
            self::LineQuantity => 'la quantité',
            self::LineUnitCode => 'l’unité de mesure',
            self::LineNetAmount => 'le montant hors taxes de la ligne',
            self::ItemName => 'la désignation de l’article',
            self::LineVatCategoryCode => 'la catégorie de TVA de la ligne',
            self::LineVatRate => 'le taux de TVA de la ligne',
        };
    }

    /** Le terme porte-t-il sur le vendeur ? Sert à grouper un rapport de complétude. */
    public function concerneLeVendeur(): bool
    {
        return \in_array($this, [
            self::SellerName,
            self::SellerVatIdentifier,
            self::SellerLegalIdentifier,
            self::SellerStreet,
            self::SellerCity,
            self::SellerPostcode,
            self::SellerCountryCode,
        ], true);
    }
}
