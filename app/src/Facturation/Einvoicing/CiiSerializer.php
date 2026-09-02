<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\NatureFacture;

/**
 * LE FICHIER EUROPÉEN — syntaxe UN/CEFACT CII, celle que Factur-X transporte.
 *
 * EN 16931 est le modèle **sémantique** : il dit quelles informations une facture doit porter. Il ne
 * dit pas comment les écrire. Deux syntaxes le réalisent : UBL 2.1 et UN/CEFACT CII. La France, via
 * Factur-X, a retenu CII — c'est le XML qu'on enfouit dans le PDF/A-3, et celui que Chorus Pro et
 * les plateformes de dématérialisation acceptent.
 *
 * ── ⚠ IL REFUSE PLUTÔT QUE DE PRODUIRE UN FICHIER QUI A L'AIR VALIDE ───────────────────────────
 *
 * C'est la décision de conception de ce fichier, et elle n'est pas de la prudence : un XML bien
 * formé avec `<ram:Name/>` vide **passe le schéma** et se fait refuser à l'arrivée, des semaines
 * plus tard, par une plateforme qui répond un code d'erreur. Entre-temps, l'exploitant croit avoir
 * facturé.
 *
 * Un fichier absent se voit tout de suite. Un fichier vide se voit dans un mois. On refuse donc, en
 * nommant chaque terme manquant — `InvoiceReadiness` les connaît déjà, on ne redit pas la règle
 * deux fois.
 *
 * ── CE QUE CE SÉRIALISEUR NE PROUVE PAS ─────────────────────────────────────────────────────────
 *
 * ⚠ **PRODUIRE UN CII N'EST PAS ÊTRE CONFORME.** EN 16931 compte une centaine de règles métier
 * (`BR-xx`) que seul le schematron officiel vérifie, plus celles de chaque CIUS national
 * (Chorus/PDP en France, XRechnung en Allemagne, SdI en Italie). Ce fichier porte les 28 termes
 * obligatoires dans la bonne structure ; il n'a jamais été soumis à un validateur.
 *
 * Le dire ici est ce qui empêche « le XML sort » de devenir « on est conforme » dans une réunion.
 *
 * ⚠ **ET IL NE FABRIQUE PAS LE PDF.** Factur-X est un PDF/A-3 avec ce XML en pièce jointe. Le PDF
 * existe ailleurs dans le produit ; les assembler est un lot distinct, qui n'est pas fait.
 */
final class CiiSerializer
{
    private const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    /**
     * L'identifiant de la spécification suivie (BT-24).
     *
     * ⚠ On déclare EN 16931 **nu**, pas un CIUS national. Annoncer `urn:cen.eu:en16931:2017#…#…` en
     * nommant Chorus reviendrait à affirmer qu'on respecte ses restrictions supplémentaires — ce que
     * personne n'a vérifié. Un profil déclaré et non tenu est pire qu'un profil générique.
     */
    private const SPECIFICATION = 'urn:cen.eu:en16931:2017';

    public function __construct(
        private readonly InvoiceReadiness $readiness,
    ) {
    }

    /**
     * @throws InvoiceNotEmittableException si un terme obligatoire manque
     */
    public function serialize(Facture $facture): string
    {
        $manques = $this->readiness->manques($facture);
        if ($manques !== []) {
            throw InvoiceNotEmittableException::pour($facture, $manques);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $racine = $dom->createElementNS(self::NS_RSM, 'rsm:CrossIndustryInvoice');
        $racine->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::NS_RAM);
        $racine->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::NS_UDT);
        $dom->appendChild($racine);

        $racine->appendChild($this->contexte($dom));
        $racine->appendChild($this->document($dom, $facture));
        $racine->appendChild($this->transaction($dom, $facture));

        return (string) $dom->saveXML();
    }

    /** BT-24 — quelle spécification ce fichier prétend suivre. */
    private function contexte(\DOMDocument $dom): \DOMElement
    {
        $contexte = $dom->createElementNS(self::NS_RSM, 'rsm:ExchangedDocumentContext');
        $parametre = $dom->createElementNS(self::NS_RAM, 'ram:GuidelineSpecifiedDocumentContextParameter');
        $parametre->appendChild($dom->createElementNS(self::NS_RAM, 'ram:ID', self::SPECIFICATION));
        $contexte->appendChild($parametre);

        return $contexte;
    }

    /** BT-1 (numéro), BT-3 (type), BT-2 (date d'émission). */
    private function document(\DOMDocument $dom, Facture $facture): \DOMElement
    {
        $doc = $dom->createElementNS(self::NS_RSM, 'rsm:ExchangedDocument');
        $doc->appendChild($dom->createElementNS(self::NS_RAM, 'ram:ID', (string) $facture->getNumero()));
        $doc->appendChild($dom->createElementNS(self::NS_RAM, 'ram:TypeCode', self::typeCode($facture)));

        $emission = $dom->createElementNS(self::NS_RAM, 'ram:IssueDateTime');
        $date = $dom->createElementNS(
            self::NS_UDT,
            'udt:DateTimeString',
            // ⚠ Format 102 = AAAAMMJJ, imposé par la syntaxe. Une date ISO passerait le schéma et
            // serait rejetée par le schematron : deux niveaux de validation, deux exigences.
            (string) $facture->getDateEmission()?->format('Ymd'),
        );
        $date->setAttribute('format', '102');
        $emission->appendChild($date);
        $doc->appendChild($emission);

        return $doc;
    }

    /**
     * BT-3 — le code de type de document, liste UNTDID 1001.
     *
     * ⚠ Il se déduit sans ambiguïté de la nature, qui est une énumération toujours posée. C'est
     * pourquoi le terme est exempté du contrôle de couverture : il ne peut pas manquer.
     */
    private static function typeCode(Facture $facture): string
    {
        return match ($facture->getNature()) {
            NatureFacture::Avoir => '381',
            NatureFacture::Acompte => '386',
            default => '380',
        };
    }

    private function transaction(\DOMDocument $dom, Facture $facture): \DOMElement
    {
        $transaction = $dom->createElementNS(self::NS_RSM, 'rsm:SupplyChainTradeTransaction');

        foreach ($facture->getLignes() as $i => $ligne) {
            \assert($ligne instanceof LigneFacture);
            $transaction->appendChild($this->ligne($dom, $ligne, $i + 1, $facture->getCurrency()));
        }

        $transaction->appendChild($this->parties($dom, $facture));
        // Vide, mais OBLIGATOIRE dans la syntaxe : une livraison non renseignée reste une balise.
        $transaction->appendChild($dom->createElementNS(self::NS_RAM, 'ram:ApplicableHeaderTradeDelivery'));
        $transaction->appendChild($this->reglement($dom, $facture));

        return $transaction;
    }

    /** BT-126, BT-153, BT-129/130, BT-131, BT-151/152. */
    private function ligne(\DOMDocument $dom, LigneFacture $ligne, int $rang, string $devise): \DOMElement
    {
        $item = $dom->createElementNS(self::NS_RAM, 'ram:IncludedSupplyChainTradeLineItem');

        $doc = $dom->createElementNS(self::NS_RAM, 'ram:AssociatedDocumentLineDocument');
        $doc->appendChild($dom->createElementNS(self::NS_RAM, 'ram:LineID', (string) $rang));
        $item->appendChild($doc);

        $produit = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedTradeProduct');
        $produit->appendChild($this->texte($dom, 'ram:Name', $ligne->getDesignation()));
        $item->appendChild($produit);

        $accord = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedLineTradeAgreement');
        $prix = $dom->createElementNS(self::NS_RAM, 'ram:NetPriceProductTradePrice');
        $prix->appendChild($dom->createElementNS(self::NS_RAM, 'ram:ChargeAmount', $ligne->getPrixUnitaireHT()));
        $accord->appendChild($prix);
        $item->appendChild($accord);

        $livraison = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedLineTradeDelivery');
        $quantite = $dom->createElementNS(self::NS_RAM, 'ram:BilledQuantity', (string) $ligne->getQuantite());
        $quantite->setAttribute('unitCode', $ligne->getUnitCode()->value);
        $livraison->appendChild($quantite);
        $item->appendChild($livraison);

        $reglement = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedLineTradeSettlement');

        $taxe = $dom->createElementNS(self::NS_RAM, 'ram:ApplicableTradeTax');
        $taxe->appendChild($dom->createElementNS(self::NS_RAM, 'ram:TypeCode', 'VAT'));
        $taux = $ligne->getTauxTva();
        $taxe->appendChild($dom->createElementNS(
            self::NS_RAM,
            'ram:CategoryCode',
            (string) $taux?->getVatCategory()?->value,
        ));
        $taxe->appendChild($dom->createElementNS(
            self::NS_RAM,
            'ram:RateApplicablePercent',
            (string) $ligne->getTauxTvaValeur(),
        ));
        $reglement->appendChild($taxe);

        $total = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedTradeSettlementLineMonetarySummation');
        $montant = $dom->createElementNS(self::NS_RAM, 'ram:LineTotalAmount', $ligne->getMontantHT());
        $montant->setAttribute('currencyID', $devise);
        $total->appendChild($montant);
        $reglement->appendChild($total);

        $item->appendChild($reglement);

        return $item;
    }

    /** BT-27/30/31/35/37/38/40 (vendeur) et BT-44/50/52/53/55 (acheteur). */
    private function parties(\DOMDocument $dom, Facture $facture): \DOMElement
    {
        $accord = $dom->createElementNS(self::NS_RAM, 'ram:ApplicableHeaderTradeAgreement');

        $profil = $facture->getProfilExploitant();
        $vendeur = $dom->createElementNS(self::NS_RAM, 'ram:SellerTradeParty');
        $vendeur->appendChild($this->texte($dom, 'ram:Name', (string) $profil?->getRaisonSociale()));

        $legal = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedLegalOrganization');
        $legal->appendChild($dom->createElementNS(self::NS_RAM, 'ram:ID', (string) $profil?->getSiren()));
        $vendeur->appendChild($legal);

        $vendeur->appendChild($this->adresse($dom, (array) $profil?->getAdresse()));

        $tva = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedTaxRegistration');
        $idTva = $dom->createElementNS(self::NS_RAM, 'ram:ID', (string) $profil?->getTvaIntracommunautaire());
        $idTva->setAttribute('schemeID', 'VA');
        $tva->appendChild($idTva);
        $vendeur->appendChild($tva);

        $accord->appendChild($vendeur);

        $destinataire = $facture->getDestinataire();
        $acheteur = $dom->createElementNS(self::NS_RAM, 'ram:BuyerTradeParty');
        $acheteur->appendChild($this->texte($dom, 'ram:Name', (string) $destinataire?->getRaisonSociale()));
        $acheteur->appendChild($this->adresse($dom, (array) $destinataire?->getAdresse()));
        $accord->appendChild($acheteur);

        return $accord;
    }

    /** @param array<string, mixed> $adresse */
    private function adresse(\DOMDocument $dom, array $adresse): \DOMElement
    {
        $noeud = $dom->createElementNS(self::NS_RAM, 'ram:PostalTradeAddress');
        $noeud->appendChild($this->texte($dom, 'ram:PostcodeCode', (string) ($adresse['cp'] ?? '')));
        $noeud->appendChild($this->texte($dom, 'ram:LineOne', (string) ($adresse['rue'] ?? '')));
        $noeud->appendChild($this->texte($dom, 'ram:CityName', (string) ($adresse['ville'] ?? '')));
        $noeud->appendChild($this->texte($dom, 'ram:CountryID', (string) ($adresse['pays'] ?? '')));

        return $noeud;
    }

    /** BT-5 (devise) et BT-106/109/110/112/115 (totaux). */
    private function reglement(\DOMDocument $dom, Facture $facture): \DOMElement
    {
        $devise = $facture->getCurrency();
        $reglement = $dom->createElementNS(self::NS_RAM, 'ram:ApplicableHeaderTradeSettlement');
        $reglement->appendChild($dom->createElementNS(self::NS_RAM, 'ram:InvoiceCurrencyCode', $devise));

        $totaux = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');

        foreach ([
            ['ram:LineTotalAmount', $facture->getTotalHT(), false],
            ['ram:TaxBasisTotalAmount', $facture->getTotalHT(), false],
            ['ram:TaxTotalAmount', $facture->getTotalTVA(), true],
            ['ram:GrandTotalAmount', $facture->getTotalTTC(), false],
            ['ram:DuePayableAmount', $facture->getSoldeDu(), false],
        ] as [$nom, $valeur, $porteLaDevise]) {
            $noeud = $dom->createElementNS(self::NS_RAM, $nom, $valeur);
            // ⚠ SEUL `TaxTotalAmount` porte `currencyID` dans CII, et son absence ailleurs n'est pas
            // un oubli : la syntaxe l'interdit sur les autres. En ajouter partout « pour être sûr »
            // ferait refuser le fichier.
            if ($porteLaDevise) {
                $noeud->setAttribute('currencyID', $devise);
            }
            $totaux->appendChild($noeud);
        }

        $reglement->appendChild($totaux);

        return $reglement;
    }

    /**
     * Un nœud texte dont le contenu est échappé par le DOM.
     *
     * ⚠ On ne concatène JAMAIS une valeur dans le troisième argument de `createElementNS` : il
     * n'échappe pas les entités. Une raison sociale contenant « & » produirait un XML mal formé —
     * et « Dupont & Fils » n'est pas un cas tordu, c'est un nom d'entreprise.
     */
    private function texte(\DOMDocument $dom, string $nom, string $valeur): \DOMElement
    {
        $noeud = $dom->createElementNS(self::NS_RAM, $nom);
        $noeud->appendChild($dom->createTextNode($valeur));

        return $noeud;
    }
}
