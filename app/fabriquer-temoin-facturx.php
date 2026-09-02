<?php

declare(strict_types=1);

/*
 * FABRIQUE UN FACTUR-X TÉMOIN, À SOUMETTRE AUX VALIDATEURS.
 *
 * ⚠ LA DONNÉE VIENT DE LA MÊME FACTURE QUE LES TESTS, PAS DE LA PRÉPRODUCTION. Aucune facture réelle
 * n'est émettable (l'adresse de l'acheteur manque), et inventer une adresse pour m'en fabriquer une
 * reviendrait à valider un document que le produit ne sait pas encore produire.
 *
 * Ce fichier n'est pas un test : c'est l'outil qui donne un artefact aux validateurs externes.
 */

require __DIR__ . '/vendor/autoload.php';

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatCategory;
use App\Facturation\Einvoicing\CiiSerializer;
use App\Facturation\Einvoicing\FacturXAssembler;
use App\Facturation\Einvoicing\InvoiceMentions;
use App\Facturation\Einvoicing\InvoiceReadiness;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Enum\UnitCode;

$profil = (new ProfilExploitant())
    ->setRaisonSociale('Regie des Sports')
    ->setSiren('130025265')
    ->setTvaIntracommunautaire('FR12130025265')
    ->setAdresse(['rue' => '2 rue du Port', 'cp' => '34200', 'ville' => 'Sete', 'pays' => 'FR'])
    ->setElectronicAddress('facturation@regie-des-sports.test');

$destinataire = (new DestinataireFacturation())
    ->setRaisonSociale('Commune de Test')
    ->setAdresse(['rue' => '1 rue de la Mairie', 'cp' => '75001', 'ville' => 'Paris', 'pays' => 'FR'])
    ->setElectronicAddress('compta@commune-de-test.test');

$taux = (new TauxTva())
    ->setLibelle('Taux normal 20 %')
    ->setTaux('20.00')
    ->setVatCategory(VatCategory::Standard);

$facture = (new Facture())
    ->setStatut(StatutFacture::Emise)
    ->setNumero('FA-2026-0007')
    ->setDateEmission(new DateTimeImmutable('2026-08-31'))
    ->setProfilExploitant($profil)
    ->setDestinataire($destinataire);

$ligne = (new LigneFacture())
    ->setDesignation('Location de creneau')
    ->setQuantite(3)
    ->setUnitCode(UnitCode::Hour)
    ->setPrixUnitaireHT('25.00')
    ->setTauxTva($taux);
$ligne->recalculer();

$facture->addLigne($ligne);
$facture->recalculerTotaux();

$html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11pt}</style></head>'
    . '<body><h1>Facture FA-2026-0007</h1>'
    . '<p>Regie des Sports &mdash; 2 rue du Port, 34200 Sete</p>'
    . '<p>Commune de Test &mdash; 1 rue de la Mairie, 75001 Paris</p>'
    . '<table><tr><td>Location de creneau</td><td>3 h</td><td>25,00</td><td>75,00</td></tr></table>'
    . '<p>Total HT 75,00 &mdash; TVA 15,00 &mdash; Total TTC 90,00</p>'
    . '</body></html>';

/*
 * ⚠ LES TROIS MENTIONS PORTENT UN TEXTE VISIBLEMENT DE DEMONSTRATION.
 *
 * BR-FR-05 exige trois notes (PMT, PMD, AAB). Ce temoin doit prouver que le serialiseur les EMET,
 * pas fournir une redaction. Les phrases ci-dessous disent explicitement qu'elles sont a rediger :
 * si ce fichier finissait par erreur devant un client, il se denoncerait lui-meme.
 *
 * En production, ces textes viennent de `ParametreFacturationEtablissement`, ecrits par l'exploitant.
 */
$mentions = new class implements InvoiceMentions {
    public function pour(Facture $facture): array
    {
        return [
            'PMT' => 'TEXTE DE DEMONSTRATION — la mention des frais de recouvrement reste a rediger par l exploitant.',
            'PMD' => 'TEXTE DE DEMONSTRATION — la mention des penalites de retard reste a rediger par l exploitant.',
            'AAB' => 'TEXTE DE DEMONSTRATION — la mention d escompte reste a rediger par l exploitant.',
        ];
    }
};

$serialiseur = new CiiSerializer(new InvoiceReadiness(), $mentions);
$assembleur = new FacturXAssembler($serialiseur);

$sortie = $argv[1] ?? '/tmp/temoin-facturx.pdf';
file_put_contents($sortie, $assembleur->assemble($html, $facture));

printf("%s ecrit (%d octets)%s", $sortie, filesize($sortie), PHP_EOL);

// Le XML seul, pour le schematron.
$xmlSortie = preg_replace('/\.pdf$/', '.xml', $sortie) ?? $sortie . '.xml';
file_put_contents($xmlSortie, $serialiseur->serialize($facture));

printf("%s ecrit (%d octets)%s", $xmlSortie, filesize($xmlSortie), PHP_EOL);
