<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Sepa\Entity\MandatSepa;

/**
 * Compose le TEXTE FACTUEL du mandat de prélèvement SEPA à partir des données stables du mandat.
 *
 * ⚠ Contrairement au contrat d'abonnement, on ne GÈLE pas ce texte dans une entité : les données du
 * mandat qui le composent (RUM, IBAN, débiteur, créancier) ne bougent pas — un mandat ne se modifie
 * pas, on en révoque un et on en signe un autre. Le texte est donc REPRODUCTIBLE depuis le mandat, et
 * le `documentHash` porté par la signature suffit à prouver qu'il n'a pas changé.
 *
 * ⚠ FACTUEL, PAS LE LIBELLÉ RÉGLEMENTAIRE. La mention opposable exacte du mandat SEPA (texte EPC)
 * reste à confirmer ; on ne l'invente pas. Ce document dit l'autorisation et ses données de façon
 * factuelle ; le libellé réglementaire s'insérera ici une fois validé.
 */
final class SepaMandateComposer
{
    public function compose(MandatSepa $mandate): string
    {
        $creancier = trim((string) $mandate->getEtablissement()?->getNom());
        $iban4 = $mandate->getIban4Derniers();
        $bic = $mandate->getBicDebiteur();

        return implode("\n", [
            'MANDAT DE PRÉLÈVEMENT SEPA',
            '',
            'Référence unique de mandat (RUM) : ' . $mandate->getRum(),
            'Créancier : ' . ($creancier !== '' ? $creancier : '(établissement non renseigné)'),
            'Débiteur (titulaire du compte) : ' . $mandate->getDebiteurNom(),
            'IBAN : **** **** **** ' . ($iban4 !== '' ? $iban4 : '????'),
            'BIC : ' . ($bic !== '' ? $bic : '(non renseigné)'),
            'Type de paiement : récurrent',
            '',
            'En signant ce mandat, le débiteur autorise le créancier à émettre des prélèvements sur le',
            'compte désigné et sa banque à les exécuter. [Mention SEPA réglementaire à finaliser.]',
        ]);
    }
}
