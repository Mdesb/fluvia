<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\StatutFacture;

/**
 * CE QUI MANQUE À UNE FACTURE POUR ÊTRE ÉMISE AU FORMAT EUROPÉEN — terme par terme.
 *
 * ── POURQUOI CE CONTRÔLE VIENT AVANT LE SÉRIALISEUR ──────────────────────────────────────────────
 *
 * Un sérialiseur qui écrit du XML avec des champs vides produit un fichier qui *ressemble* à une
 * facture et qu'aucune plateforme n'acceptera. Pire : il donne l'impression que le format est fait.
 *
 * ⚠ **MESURE DU 31/08, ET ELLE CHANGE L'ORDRE DES TRAVAUX.** L'identité du VENDEUR n'existe pas
 * dans ce modèle de données :
 *
 *     ProfilExploitant   ne porte qu'un SIREN — ni raison sociale, ni adresse, ni n° de TVA
 *     Etablissement      ne porte AUCUNE adresse (nom, région, fuseau horaire, et c'est tout)
 *
 * Aucune facture conforme n'est donc émettable aujourd'hui — ni en France, ni en Espagne, ni
 * ailleurs. Ce n'est pas un manque de connecteur : c'est un manque de donnée, et aucun connecteur
 * ne le comblera.
 *
 * Ce contrôle existe pour que ça se dise en une commande, avec les codes `BT-xx` qu'un comptable ou
 * un intégrateur reconnaît, plutôt qu'après un refus de dépôt.
 *
 * ── CE QU'IL NE FAIT PAS, ET QU'IL NE FAUT PAS LAISSER CROIRE ────────────────────────────────────
 *
 * Il vérifie la PRÉSENCE des termes obligatoires. Il ne vérifie **aucune** des règles métier de la
 * norme (`BR-xx` : cohérence des totaux, catégories de TVA admissibles, exonérations motivées…),
 * ni les restrictions nationales (CIUS). Une facture qui passe ici n'est pas conforme : elle est
 * *complète*. La conformité se prouve contre un schematron, ce qui reste à faire.
 */
final class InvoiceReadiness
{
    /**
     * @return list<array{terme: BusinessTerm, ou: string}> les termes absents, vides d'abord
     */
    public function manques(Facture $facture): array
    {
        $manques = [];

        $ajouter = static function (BusinessTerm $t, string $ou) use (&$manques): void {
            $manques[] = ['terme' => $t, 'ou' => $ou];
        };

        // ── En-tête ────────────────────────────────────────────────────────────────────────────
        //
        // ⚠ UN BROUILLON N'A LEGITIMEMENT NI NUMERO NI DATE : ils sont posés AU MOMENT de
        // l'émission. Les compter comme des manques mélangerait « pas encore émise » et « émise et
        // incomplète » — deux causes qui ne se corrigent pas au même endroit, et un rapport qui les
        // confond fait chercher au mauvais endroit.
        $emise = $facture->getStatut() !== StatutFacture::Brouillon;

        if ($emise && ($facture->getNumero() ?? '') === '') {
            $ajouter(BusinessTerm::InvoiceNumber, 'Facture::numero');
        }
        if ($emise && $facture->getDateEmission() === null) {
            $ajouter(BusinessTerm::IssueDate, 'Facture::dateEmission');
        }

        // ⚠ LA DEVISE N'EXISTE NULLE PART. `EUR` est implicite dans tout le dépôt. Tant qu'on ne
        // vend qu'en France ça ne se voit pas ; c'est le premier mur d'une vente hors zone euro, et
        // EN 16931 exige BT-5 explicitement.
        $ajouter(BusinessTerm::CurrencyCode, 'aucun champ — `EUR` est implicite partout');

        // ── Vendeur ────────────────────────────────────────────────────────────────────────────
        //
        // ⚠ AUCUN de ces termes n'a de champ dans le modèle. On ne les cherche donc pas : on les
        // déclare absents, en nommant l'entité qui devrait les porter. Chercher une valeur dans un
        // champ inexistant produirait un `null` qu'on lirait comme « pas renseigné » — alors que
        // c'est « pas modélisé », et les deux ne se corrigent pas au même endroit.
        $profil = $facture->getProfilExploitant();
        if ($profil === null || $profil->getSiren() === '') {
            $ajouter(BusinessTerm::SellerLegalIdentifier, 'ProfilExploitant::siren');
        }
        $ajouter(BusinessTerm::SellerName, 'ProfilExploitant — champ à créer');
        $ajouter(BusinessTerm::SellerVatIdentifier, 'ProfilExploitant — champ à créer');
        $ajouter(BusinessTerm::SellerStreet, 'Etablissement — aucune adresse');
        $ajouter(BusinessTerm::SellerPostcode, 'Etablissement — aucune adresse');
        $ajouter(BusinessTerm::SellerCity, 'Etablissement — aucune adresse');
        $ajouter(BusinessTerm::SellerCountryCode, 'Etablissement — aucune adresse');

        // ── Acheteur ───────────────────────────────────────────────────────────────────────────
        $destinataire = $facture->getDestinataire();
        if ($destinataire === null) {
            $ajouter(BusinessTerm::BuyerName, 'Facture::destinataire absent');
        } else {
            $nom = trim(($destinataire->getRaisonSociale() ?? '') . ' '
                . ($destinataire->getNom() ?? '') . ' ' . ($destinataire->getPrenom() ?? ''));
            if ($nom === '') {
                $ajouter(BusinessTerm::BuyerName, 'DestinataireFacturation');
            }

            $adresse = $destinataire->getAdresse();
            foreach ([
                ['rue', BusinessTerm::BuyerStreet],
                ['cp', BusinessTerm::BuyerPostcode],
                ['ville', BusinessTerm::BuyerCity],
                ['pays', BusinessTerm::BuyerCountryCode],
            ] as [$cle, $terme]) {
                if (!isset($adresse[$cle]) || trim((string) $adresse[$cle]) === '') {
                    $ajouter($terme, sprintf('DestinataireFacturation::adresse[%s]', $cle));
                }
            }
        }

        // ── Lignes ─────────────────────────────────────────────────────────────────────────────
        //
        // ⚠ Une facture SANS ligne est un manque, pas une facture vide qu'on laisserait passer :
        // EN 16931 exige au moins une ligne (BG-25).
        $lignes = $facture->getLignes();
        if (\count($lignes) === 0) {
            $ajouter(BusinessTerm::LineIdentifier, 'Facture sans aucune ligne');
        }

        foreach ($lignes as $i => $ligne) {
            \assert($ligne instanceof LigneFacture);
            $ou = sprintf('LigneFacture[%d]', $i);

            if (trim($ligne->getDesignation()) === '') {
                $ajouter(BusinessTerm::ItemName, $ou . '::designation');
            }
            if ($ligne->getTauxTva() === null) {
                $ajouter(BusinessTerm::LineVatRate, $ou . '::tauxTva');
            }
            // ⚠ L'UNITÉ DE MESURE N'EXISTE PAS. `quantite` est un entier sans unité. EN 16931 exige
            // un code UN/ECE Rec 20 (`C62` pour « unité », `HUR` pour une heure…). Sans lui, une
            // ligne « 3 » ne dit pas trois de quoi.
            $ajouter(BusinessTerm::LineUnitCode, $ou . ' — aucune unité de mesure');
        }

        return $manques;
    }

    /** Une facture est-elle émettable au format européen ? */
    public function estEmettable(Facture $facture): bool
    {
        return $this->manques($facture) === [];
    }
}
