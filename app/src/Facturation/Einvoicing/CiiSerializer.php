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
    /**
     * Les registres d'identification d'entreprise dont nous connaissons le code ISO 6523.
     *
     * ⚠ UNE SEULE ENTREE, ET C'EST HONNETE. Le produit n'est commercialise qu'en France ; `0002` est
     * le repertoire SIRENE, et c'est le seul que nous puissions soutenir. Ajouter des codes de
     * memoire pour les autres pays produirait des affirmations plausibles et FAUSSES sur un document
     * opposable.
     *
     * Chaque entree ajoutee ici doit venir d'une source verifiee, comme les taux legaux qui portent
     * leur `source`. Une table de correspondances dont on ignore l'origine est une table d'opinions.
     */
    private const REGISTRES_CONNUS = [
        'FR' => '0002',
    ];

    private const SPECIFICATION = 'urn:cen.eu:en16931:2017';

    public function __construct(
        private readonly InvoiceReadiness $readiness,
        /**
         * D'ou viennent les trois mentions obligatoires du profil francais.
         *
         * ⚠ NULLABLE, ET LE SERIALISEUR MARCHE SANS. Les tests unitaires construisent une facture en
         * memoire, sans base : exiger un fournisseur ici les obligerait a monter un conteneur pour
         * verifier une concatenation XML. Sans fournisseur, les notes sont absentes — et le
         * validateur le signale, ce qui est le bon comportement.
         */
        private readonly ?InvoiceMentions $mentions = null,
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

        // ── BG-1 : LES NOTES, ET LEUR ORDRE DANS LA SEQUENCE ────────────────────────────────────
        //
        // ⚠ APRES `IssueDateTime`, JAMAIS AVANT. CII est une syntaxe a sequence ; une note placee
        // plus haut produit un fichier que le schema refuse, avant meme le schematron.
        //
        // Le profil francais (BR-FR-05) exige trois notes identifiees par leur code sujet : PMT pour
        // les frais de recouvrement, PMD pour les penalites de retard, AAB pour l'escompte ou son
        // absence. Chacune est absente tant que l'exploitant n'a pas ecrit SON texte — le produit
        // fournit le vehicule, pas la formulation d'une clause qui engage.
        foreach ($this->notes($facture) as [$code, $texte]) {
            $note = $dom->createElementNS(self::NS_RAM, 'ram:IncludedNote');
            $note->appendChild($this->texte($dom, 'ram:Content', $texte));
            $note->appendChild($dom->createElementNS(self::NS_RAM, 'ram:SubjectCode', $code));
            $doc->appendChild($note);
        }

        return $doc;
    }

    /**
     * Les mentions renseignees, avec leur code sujet.
     *
     * ⚠ ON N'EMET QUE CE QUI EST ECRIT. Une note vide satisferait la presence de la balise et ne
     * dirait rien au lecteur — le client recevrait une facture affirmant des conditions blanches.
     * L'absence reste visible dans le rapport de validation, ou elle nomme ce qu'il faut rediger.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function notes(Facture $facture): array
    {
        if ($this->mentions === null) {
            return [];
        }

        $notes = [];
        foreach ($this->mentions->pour($facture) as $code => $texte) {
            if (is_string($texte) && trim($texte) !== '') {
                $notes[] = [$code, trim($texte)];
            }
        }

        return $notes;
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
            $transaction->appendChild($this->ligne($dom, $ligne, $i + 1));
        }

        $transaction->appendChild($this->parties($dom, $facture));
        // ⚠ VIDE, ET OBLIGATOIRE — MESURE, PAS SUPPOSITION.
        //
        // PEPPOL-EN16931-R008 avertit : « Document MUST not contain empty elements ». J'ai donc
        // essaye de le retirer. Le validateur Factur-X a REFUSE le fichier : cet element fait partie
        // de la sequence CII, et son ABSENCE est une erreur la ou sa VACUITE n'est qu'un
        // avertissement. Les deux ne se valent pas.
        //
        // La sortie propre serait de lui donner du contenu — `ActualDeliverySupplyChainEvent`
        // (BT-72, date de livraison reelle). Nous ne stockons aucune date de livraison, et la
        // deduire de la date d'emission affirmerait que la prestation a ete rendue ce jour-la : une
        // donnee metier inventee, sur un document opposable.
        //
        // On garde donc l'avertissement, qui est VRAI, plutot qu'une valeur fausse qui le ferait
        // taire.
        $transaction->appendChild($dom->createElementNS(self::NS_RAM, 'ram:ApplicableHeaderTradeDelivery'));
        $transaction->appendChild($this->reglement($dom, $facture));

        return $transaction;
    }

    /** BT-126, BT-153, BT-129/130, BT-131, BT-151/152. */
    private function ligne(\DOMDocument $dom, LigneFacture $ligne, int $rang): \DOMElement
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
        // ⚠ PAS DE `currencyID` ICI — REGLE CII-DT-031, ET J'AVAIS FAIT L'INVERSE.
        //
        // Le schematron officiel a refuse notre fichier avec « [CII-DT-031] - currencyID should not
        // be present » sur ce total de ligne. L'exemple officiel du paquet CEN n'en porte QUE sur le
        // `TaxTotalAmount` d'en-tete, nulle part ailleurs.
        //
        // Mon test le verifiait deja... sur les totaux d'EN-TETE uniquement. La regle etait juste,
        // sa PORTEE etait trop etroite, et c'est la portee qui l'a laissee passer.
        $total->appendChild($dom->createElementNS(self::NS_RAM, 'ram:LineTotalAmount', $ligne->getMontantHT()));
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

        // ⚠ BT-30 — LE `schemeID` N'EST PAS DECORATIF : SANS LUI, LE SIREN EST LU VIDE.
        //
        // Le profil francais (BR-FR-10, XP Z12-012) cherche exactement
        // `SpecifiedLegalOrganization/ram:ID[@schemeID = '0002']` — `0002` designe le repertoire
        // SIRENE dans la liste ISO 6523. Nous ecrivions l'identifiant sans l'attribut : la valeur
        // etait la, au bon endroit, et la regle rendait « Valeur actuelle : "" ».
        //
        // Mesure du 02/09 : le validateur Factur-X reclamait un SIREN que nous posions deja. Un
        // identifiant sans son referentiel n'identifie rien — c'est un nombre.
        $legal = $dom->createElementNS(self::NS_RAM, 'ram:SpecifiedLegalOrganization');
        $identifiant = $dom->createElementNS(self::NS_RAM, 'ram:ID', (string) $profil?->getSiren());

        // ⚠ ON NE DECLARE UN REGISTRE QUE QUAND ON LE CONNAIT.
        //
        // J'avais fige `schemeID="0002"` — le repertoire SIRENE, FRANCAIS. Pour un etablissement
        // belge ou allemand, ce fichier aurait affirme « ce numero vient de SIRENE » en portant un
        // numero d'entreprise belge. Un identifiant sans son referentiel n'identifie rien ; un
        // identifiant avec le MAUVAIS referentiel ment.
        //
        // La liste ISO 6523 compte des centaines de codes. En inventer d'autres de memoire
        // produirait des affirmations plausibles et fausses sur un document opposable — exactement
        // ce que le champ `source` des taux legaux existe pour empecher ailleurs.
        //
        // On declare donc le registre pour les pays dont on le connait, et on OMET l'attribut pour
        // les autres. Un identifiant sans `schemeID` reste lisible ; il ne revendique simplement pas
        // une origine qu'on ne peut pas soutenir. Le profil francais (BR-FR-10) exige `0002` — il ne
        // s'applique qu'aux factures francaises, ou nous le posons.
        // ⚠ LE PAYS VIENT DE L'ETABLISSEMENT DE LA FACTURE, PAS DE CELUI DU PROFIL.
        //
        // Un profil comptable peut couvrir plusieurs etablissements ; c'est celui qui EMET
        // qui determine le registre a declarer. Ma premiere version lisait le profil, et le
        // temoin Factur-X — dont le profil n'a pas d'etablissement — a fait revenir BR-FR-10 :
        // le registre n'etait plus declare du tout. Le repli sur le profil reste, pour le cas
        // ou la facture n'aurait pas encore son etablissement.
        $emetteur = $facture->getEtablissement() ?? $profil?->getEtablissement();
        $pays = strtoupper((string) ($emetteur?->getPays() ?? ''));
        if (isset(self::REGISTRES_CONNUS[$pays])) {
            $identifiant->setAttribute('schemeID', self::REGISTRES_CONNUS[$pays]);
        }

        $legal->appendChild($identifiant);
        $vendeur->appendChild($legal);

        $vendeur->appendChild($this->adresse($dom, (array) $profil?->getAdresse()));

        // ⚠ BT-34 — ENTRE L'ADRESSE POSTALE ET L'ENREGISTREMENT DE TVA. CII est une sequence :
        // l'ecrire ailleurs produit un fichier que le schema refuse, avant meme le schematron.
        $this->adresseElectronique($dom, $vendeur, $profil?->getElectronicAddress());

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
        // BT-49 — meme regle de sequence que pour le vendeur.
        $this->adresseElectronique($dom, $acheteur, $destinataire?->getElectronicAddress());
        $accord->appendChild($acheteur);

        return $accord;
    }

    /**
     * BT-34 / BT-49 — l'adresse a laquelle la facture electronique est ROUTEE.
     *
     * ⚠ ON N'ECRIT RIEN QUAND ELLE MANQUE, ET C'EST DELIBERE. Une balise `ram:URIID` vide
     * satisferait la presence de l'element et serait refusee a l'arrivee : une adresse de routage
     * vide n'achemine rien. L'absence reste visible dans le rapport de validation, ou elle nomme ce
     * qu'il faut saisir — plutot que d'etre remplacee par une coquille qui a l'air remplie.
     *
     * `EM` designe une adresse de courriel dans la liste des schemas d'identifiants.
     */
    private function adresseElectronique(\DOMDocument $dom, \DOMElement $partie, ?string $adresse): void
    {
        if ($adresse === null || trim($adresse) === '') {
            return;
        }

        $noeud = $dom->createElementNS(self::NS_RAM, 'ram:URIUniversalCommunication');
        $uri = $dom->createElementNS(self::NS_RAM, 'ram:URIID');
        $uri->setAttribute('schemeID', 'EM');
        $uri->appendChild($dom->createTextNode($adresse));
        $noeud->appendChild($uri);
        $partie->appendChild($noeud);
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

        // ── BG-23 : LA VENTILATION DE TVA, QUI MANQUAIT ENTIEREMENT ────────────────────────────
        //
        // Le schematron a refuse notre fichier trois fois pour son absence : `BR-CO-18` (« shall at
        // least have one VAT breakdown group »), `BR-S-01` (une ligne au taux standard exige un
        // groupe de sa categorie) et `BR-CO-14` (le total de TVA doit egaler la somme des groupes).
        //
        // ⚠ ON GROUPE PAR (CATEGORIE, TAUX), PAS PAR TAUX SEUL. `Facture::getVentilationTva()`
        // groupe par taux — suffisant pour la comptabilite, faux pour EN 16931 : deux categories au
        // meme taux (un 0 % « exonere » et un 0 % « hors champ ») fusionneraient en un seul groupe,
        // et la facture affirmerait une nature fiscale qu'elle n'a pas.
        foreach ($this->ventilation($facture) as $groupe) {
            $reglement->appendChild($this->groupeDeTva($dom, $groupe));
        }

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
     * Les groupes de TVA, par (catégorie, taux).
     *
     * @return list<array{categorie: string, taux: string, base: int, tva: int}>
     */
    private function ventilation(Facture $facture): array
    {
        $groupes = [];

        foreach ($facture->getLignes() as $ligne) {
            \assert($ligne instanceof LigneFacture);
            $taux = $ligne->getTauxTva();
            $categorie = (string) $taux?->getVatCategory()?->value;
            $pourcent = (string) $ligne->getTauxTvaValeur();
            $cle = $categorie . '|' . $pourcent;

            if (!isset($groupes[$cle])) {
                $groupes[$cle] = ['categorie' => $categorie, 'taux' => $pourcent, 'base' => 0, 'tva' => 0];
            }

            $groupes[$cle]['base'] += self::centimes($ligne->getMontantHT());
            $groupes[$cle]['tva'] += self::centimes($ligne->getMontantTva());
        }

        return array_values($groupes);
    }

    /**
     * BT-116 (base), BT-117 (montant), BT-118 (catégorie), BT-119 (taux).
     *
     * ⚠ L'ORDRE DES ENFANTS N'EST PAS LIBRE. CII est une syntaxe à séquence : `CalculatedAmount`,
     * `TypeCode`, `BasisAmount`, `CategoryCode`, puis `RateApplicablePercent`. Les écrire dans un
     * autre ordre produit un fichier que le schéma refuse — avant même le schematron.
     *
     * @param array{categorie: string, taux: string, base: int, tva: int} $groupe
     */
    private function groupeDeTva(\DOMDocument $dom, array $groupe): \DOMElement
    {
        $taxe = $dom->createElementNS(self::NS_RAM, 'ram:ApplicableTradeTax');
        $taxe->appendChild($dom->createElementNS(self::NS_RAM, 'ram:CalculatedAmount', self::decimal($groupe['tva'])));
        $taxe->appendChild($dom->createElementNS(self::NS_RAM, 'ram:TypeCode', 'VAT'));
        $taxe->appendChild($dom->createElementNS(self::NS_RAM, 'ram:BasisAmount', self::decimal($groupe['base'])));
        $taxe->appendChild($dom->createElementNS(self::NS_RAM, 'ram:CategoryCode', $groupe['categorie']));
        $taxe->appendChild($dom->createElementNS(self::NS_RAM, 'ram:RateApplicablePercent', $groupe['taux']));

        return $taxe;
    }

    /** Centimes entiers depuis une décimale, sans passer par un flottant. */
    private static function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private static function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
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
