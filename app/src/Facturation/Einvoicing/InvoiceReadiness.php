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
 * **CE QUE LA PREMIÈRE MESURE A TROUVÉ, ET QUI EST CORRIGÉ DEPUIS.** Le 31/08 au matin, l'identité
 * du vendeur n'existait pas : `ProfilExploitant` ne portait qu'un SIREN, `Etablissement` aucune
 * adresse. Aucune facture n'était émettable — et ce n'était pas un manque de connecteur, c'était un
 * manque de **donnée**, qu'aucun raccordement n'aurait comblé.
 *
 * `ProfilExploitant` porte désormais `raisonSociale`, `tvaIntracommunautaire` et l'adresse du siège
 * (migration `Version20260831180000`). Ce contrôle les **lit** au lieu de les déclarer absents.
 *
 * ⚠ Les champs sont nullables : les profils existants sont vides. Le rapport dit donc encore qu'il
 * manque quelque chose — mais il désigne maintenant une **saisie à faire**, plus un modèle à
 * changer. Les deux ne se corrigent pas au même endroit, et les confondre ferait chercher au
 * mauvais.
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

        // BT-5 — POSÉE LE 02/09. Elle n'existait nulle part : `EUR` était implicite dans tout le
        // dépôt, et EN 16931 exige BT-5 explicitement.
        //
        // ⚠ ON LA VÉRIFIE QUAND MÊME, ET CE N'EST PAS DE LA PARANOÏA. La colonne est `NOT NULL` avec
        // un défaut, donc elle porte toujours quelque chose — mais « toujours remplie » n'est pas
        // « toujours valide ». Trois caractères ISO 4217 est la seule forme qu'un validateur
        // européen accepte ; une chaîne vide ou `eur` en minuscules passerait la contrainte de base
        // et serait refusée à l'arrivée.
        if (preg_match('/^[A-Z]{3}$/', $facture->getCurrency()) !== 1) {
            $ajouter(BusinessTerm::CurrencyCode, 'Facture::currency — code ISO 4217 attendu');
        }

        // ── Vendeur ────────────────────────────────────────────────────────────────────────────
        //
        // ⚠ AUCUN de ces termes n'a de champ dans le modèle. On ne les cherche donc pas : on les
        // déclare absents, en nommant l'entité qui devrait les porter. Chercher une valeur dans un
        // champ inexistant produirait un `null` qu'on lirait comme « pas renseigné » — alors que
        // c'est « pas modélisé », et les deux ne se corrigent pas au même endroit.
        $profil = $facture->getProfilExploitant();

        if ($profil === null) {
            // Sans profil, aucun des six termes ne peut être renseigné : on les nomme tous plutôt
            // que de rendre un seul manque qui ferait chercher au mauvais endroit.
            foreach ([
                BusinessTerm::SellerLegalIdentifier,
                BusinessTerm::SellerName,
                BusinessTerm::SellerVatIdentifier,
                BusinessTerm::SellerStreet,
                BusinessTerm::SellerPostcode,
                BusinessTerm::SellerCity,
                BusinessTerm::SellerCountryCode,
            ] as $terme) {
                $ajouter($terme, 'Facture::profilExploitant absent');
            }
        } else {
            if ($profil->getSiren() === '') {
                $ajouter(BusinessTerm::SellerLegalIdentifier, 'ProfilExploitant::siren');
            }
            if (trim($profil->getRaisonSociale() ?? '') === '') {
                $ajouter(BusinessTerm::SellerName, 'ProfilExploitant::raisonSociale');
            }
            if (trim($profil->getTvaIntracommunautaire() ?? '') === '') {
                $ajouter(BusinessTerm::SellerVatIdentifier, 'ProfilExploitant::tvaIntracommunautaire');
            }

            $siege = $profil->getAdresse();
            foreach ([
                ['rue', BusinessTerm::SellerStreet],
                ['cp', BusinessTerm::SellerPostcode],
                ['ville', BusinessTerm::SellerCity],
                ['pays', BusinessTerm::SellerCountryCode],
            ] as [$cle, $terme]) {
                if (!isset($siege[$cle]) || trim((string) $siege[$cle]) === '') {
                    $ajouter($terme, sprintf('ProfilExploitant::adresse[%s]', $cle));
                }
            }
        }

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

            // BT-131 — le montant net de la ligne. Il existe toujours ; ce qui peut manquer, c'est
            // sa COHERENCE avec la quantite et le prix unitaire. Une ligne dont le montant ne suit
            // pas ses propres facteurs est refusee par le validateur europeen (BR-24), et se lit
            // comme une erreur de saisie chez le client bien avant.
            $attendu = self::centimes($ligne->getPrixUnitaireHT()) * $ligne->getQuantite();
            if (self::centimes($ligne->getMontantHT()) !== $attendu) {
                $ajouter(
                    BusinessTerm::LineNetAmount,
                    sprintf('%s::montantHT — %s au lieu de %s', $ou, $ligne->getMontantHT(), self::decimal($attendu)),
                );
            }

            // BT-151 — LA CATEGORIE, PORTEE PAR LE TAUX DEPUIS LE 02/09.
            //
            // ⚠ ON NE LA DEDUIT PAS QUAND LE TAUX VAUT ZERO, ET LA MESURE LE JUSTIFIE. Six taux a
            // 0 % existent en base, tous libelles « Hors champ (operation non commerciale) » : c'est
            // `O`, pas `Z` (taux zero) ni `E` (exonere). Les trois se ressemblent sur une facture,
            // se distinguent au controle fiscal, et n'appellent pas les memes mentions. « 0 % donc
            // Z » aurait ete faux pour les six.
            //
            // Un taux sans categorie n'est donc pas un defaut du code : c'est un arbitrage fiscal
            // qui n'a pas ete rendu. Le rapport le nomme au lieu de le remplir.
            $taux = $ligne->getTauxTva();
            if ($taux !== null && $taux->getVatCategory() === null) {
                $ajouter(
                    BusinessTerm::LineVatCategoryCode,
                    sprintf('%s — le taux « %s » n a pas de categorie EN 16931', $ou, $taux->getLibelle()),
                );
            }
            // BT-130 — POSÉE LE 02/09. `quantite` était un entier sans unité : une ligne « 3 » ne
            // disait pas trois de quoi. Le champ porte désormais un code UN/ECE Rec 20, et le type
            // énuméré garantit qu'il en est un — il n'y a donc rien à vérifier ici, seulement à ne
            // plus déclarer le terme manquant.
        }

        // ── Totaux ─────────────────────────────────────────────────────────────────────────────
        //
        // ⚠ ON NE VERIFIE PAS LEUR PRESENCE, ON VERIFIE LEURS EGALITES. Ces colonnes sont `NOT NULL`
        // avec un defaut `0.00` : elles valent toujours quelque chose. Un controle de presence
        // produirait une branche morte qu'on lirait comme une couverture.
        //
        // EN 16931 impose les egalites (BR-12 a BR-15). Une facture dont les totaux ne suivent pas
        // ses lignes est refusee a l'arrivee — et pour le client, c'est une facture fausse.
        $sommeLignes = 0;
        foreach ($lignes as $ligne) {
            \assert($ligne instanceof LigneFacture);
            $sommeLignes += self::centimes($ligne->getMontantHT());
        }

        $ht = self::centimes($facture->getTotalHT());
        $tva = self::centimes($facture->getTotalTVA());
        $ttc = self::centimes($facture->getTotalTTC());

        if ($sommeLignes !== $ht) {
            $ajouter(
                BusinessTerm::SumOfLineNetAmounts,
                sprintf('Facture::totalHT — %s, somme des lignes %s', $facture->getTotalHT(), self::decimal($sommeLignes)),
            );
        }

        // BT-109 porte la meme valeur que BT-106 tant qu'il n'y a ni remise ni frais au niveau du
        // document. Le jour ou ces niveaux existeront, l'egalite cessera d'etre vraie et il faudra
        // la reecrire — c'est ecrit ici pour qu'on le sache alors.
        if ($ht !== $sommeLignes) {
            $ajouter(BusinessTerm::TotalWithoutVat, 'Facture::totalHT — incoherent avec les lignes');
        }

        if ($ttc !== $ht + $tva) {
            $ajouter(
                BusinessTerm::TotalVatAmount,
                sprintf('Facture::totalTTC (%s) != totalHT + totalTVA', $facture->getTotalTTC()),
            );
            $ajouter(BusinessTerm::TotalWithVat, 'Facture::totalTTC — incoherent avec HT + TVA');
        }

        // BT-115 — le montant restant du. `getSoldeDu()` le calcule ; ce qui se verifie est qu'il ne
        // soit pas NEGATIF, cas qu'EN 16931 ne prevoit pas sur une facture (un trop-percu se traite
        // par un avoir, pas par un montant du negatif).
        if (self::centimes($facture->getSoldeDu()) < 0) {
            $ajouter(
                BusinessTerm::AmountDueForPayment,
                sprintf('Facture::soldeDu — %s, negatif : un trop-percu se traite par un avoir', $facture->getSoldeDu()),
            );
        }

        return $manques;
    }

    /** Centimes entiers depuis une decimale a deux chiffres, sans passer par un flottant. */
    private static function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private static function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }

    /** Une facture est-elle émettable au format européen ? */
    public function estEmettable(Facture $facture): bool
    {
        return $this->manques($facture) === [];
    }
}
