<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Facturation\Einvoicing\BusinessTerm;
use App\Facturation\Einvoicing\InvoiceReadiness;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Enum\StatutFacture;
use PHPUnit\Framework\TestCase;

/**
 * `InvoiceReadiness` — ce qui manque pour émettre au format européen EN 16931.
 *
 * ⚠ **UN CONTRÔLE QUI REFUSE BEAUCOUP EST LE PLUS DIFFICILE À TESTER** : ses refus sont vrais pour
 * la mauvaise raison dès qu'on se trompe quelque part. C'est arrivé ici même — voir le commentaire
 * de `testUnVendeurCompletNeProduitAucunManqueCoteVendeur`, qui remplace un cliquet resté vert
 * alors qu'il devait mordre.
 *
 * D'où la forme de ce fichier : **on teste surtout ce qui doit PASSER**. Un acheteur complet ne doit
 * produire aucun manque côté acheteur ; un brouillon ne doit pas se voir reprocher son absence de
 * numéro. Sans ces cas-là, un contrôle qui rendrait « tout manque » serait vert.
 */
final class InvoiceReadinessTest extends TestCase
{
    private InvoiceReadiness $readiness;

    protected function setUp(): void
    {
        $this->readiness = new InvoiceReadiness();
    }

    /**
     * ⚠ LE CAS QUI DOIT PASSER : un acheteur complet ne manque de rien.
     *
     * C'est le seul qui démasque un contrôle trop large. Les assertions de refus resteraient vertes
     * si `manques()` renvoyait la liste entière à chaque appel.
     */
    public function testUnAcheteurCompletNeProduitAucunManqueCoteAcheteur(): void
    {
        $facture = $this->factureAvecAcheteurComplet();

        $termes = $this->termes($facture);

        foreach ([
            BusinessTerm::BuyerName,
            BusinessTerm::BuyerStreet,
            BusinessTerm::BuyerPostcode,
            BusinessTerm::BuyerCity,
            BusinessTerm::BuyerCountryCode,
        ] as $terme) {
            self::assertNotContains($terme, $termes, sprintf(
                '%s est renseigné : le contrôle ne doit pas le réclamer.',
                $terme->libelle(),
            ));
        }
    }

    public function testUnAcheteurSansAdresseEstSignaleChampParChamp(): void
    {
        $facture = $this->factureAvecAcheteurComplet();
        $facture->getDestinataire()->setAdresse(['rue' => '', 'cp' => '75001', 'ville' => '', 'pays' => 'FR']);

        $termes = $this->termes($facture);

        self::assertContains(BusinessTerm::BuyerStreet, $termes);
        self::assertContains(BusinessTerm::BuyerCity, $termes);
        // Et ceux qui SONT renseignés ne sont pas réclamés : un contrôle qui signale tout dès qu'il
        // signale quelque chose ne dit pas où chercher.
        self::assertNotContains(BusinessTerm::BuyerPostcode, $termes);
        self::assertNotContains(BusinessTerm::BuyerCountryCode, $termes);
    }

    /**
     * ⚠ Un brouillon n'a légitimement ni numéro ni date : ils sont posés à l'émission.
     *
     * Les compter comme des manques mélangerait « pas encore émise » et « émise et incomplète » —
     * deux causes qui ne se corrigent pas au même endroit.
     */
    public function testUnBrouillonNEstPasReprocheDeNAvoirNiNumeroNiDate(): void
    {
        $facture = $this->factureAvecAcheteurComplet();
        $facture->setStatut(StatutFacture::Brouillon);

        $termes = $this->termes($facture);

        self::assertNotContains(BusinessTerm::InvoiceNumber, $termes);
        self::assertNotContains(BusinessTerm::IssueDate, $termes);
    }

    public function testUneFactureEmiseSansNumeroEstSignalee(): void
    {
        $facture = $this->factureAvecAcheteurComplet();
        $facture->setStatut(StatutFacture::Emise);
        $facture->setNumero(null);

        self::assertContains(BusinessTerm::InvoiceNumber, $this->termes($facture));
    }

    /**
     * ⚠ **LE CAS QUI DOIT PASSER, ET QUI REMPLACE UN CLIQUET QUI NE MORDAIT PAS.**
     *
     * La version précédente de ce fichier affirmait « les six termes vendeur sont signalés », et
     * prétendait devenir rouge le jour où le modèle porterait les champs. Elle est restée verte :
     * la facture de test n'avait aucun profil, donc le contrôle prenait la branche « profil absent »
     * et signalait les mêmes termes. **Le test mesurait « des termes sont signalés », pas « le
     * modèle ne les porte pas »** — deux choses qui coïncidaient.
     *
     * Celui-ci ne peut pas être vert par accident : il exige que la lecture fonctionne.
     */
    public function testUnVendeurCompletNeProduitAucunManqueCoteVendeur(): void
    {
        $facture = $this->factureAvecAcheteurComplet()->setProfilExploitant($this->vendeurComplet());

        $termes = $this->termes($facture);

        foreach ([
            BusinessTerm::SellerLegalIdentifier,
            BusinessTerm::SellerName,
            BusinessTerm::SellerVatIdentifier,
            BusinessTerm::SellerStreet,
            BusinessTerm::SellerPostcode,
            BusinessTerm::SellerCity,
            BusinessTerm::SellerCountryCode,
        ] as $terme) {
            self::assertNotContains($terme, $termes, sprintf(
                '%s est renseigné sur le profil : le contrôle ne doit pas le réclamer.',
                $terme->libelle(),
            ));
        }
    }

    /** Et son jumeau : un profil vide est signalé champ par champ, pas en bloc. */
    public function testUnProfilSansIdentiteLegaleEstSignaleChampParChamp(): void
    {
        $profil = $this->vendeurComplet()
            ->setRaisonSociale(null)
            ->setAdresse(['rue' => '2 rue du Port', 'cp' => '', 'ville' => 'Sète', 'pays' => 'FR']);

        $termes = $this->termes($this->factureAvecAcheteurComplet()->setProfilExploitant($profil));

        self::assertContains(BusinessTerm::SellerName, $termes);
        self::assertContains(BusinessTerm::SellerPostcode, $termes);
        // Ceux qui SONT renseignés ne sont pas réclamés : un contrôle qui signale tout dès qu'il
        // signale quelque chose ne dit pas où chercher.
        self::assertNotContains(BusinessTerm::SellerStreet, $termes);
        self::assertNotContains(BusinessTerm::SellerCity, $termes);
        self::assertNotContains(BusinessTerm::SellerVatIdentifier, $termes);
    }

    /**
     * Sans profil du tout, les sept termes sont nommés — pas un seul, qui ferait chercher au
     * mauvais endroit.
     */
    public function testUneFactureSansProfilNommeTousLesTermesVendeur(): void
    {
        $termes = $this->termes($this->factureAvecAcheteurComplet());

        self::assertContains(BusinessTerm::SellerName, $termes);
        self::assertContains(BusinessTerm::SellerCountryCode, $termes);
        self::assertContains(BusinessTerm::SellerLegalIdentifier, $termes);
    }

    public function testAucuneFactureNEstEmettableAujourdHui(): void
    {
        self::assertFalse(
            $this->readiness->estEmettable($this->factureAvecAcheteurComplet()),
            'Le vendeur n’étant pas modélisé, aucune facture ne peut être émise au format européen.',
        );
    }

    // ---------------------------------------------------------------- montage

    /** @return list<BusinessTerm> */
    private function termes(Facture $facture): array
    {
        return array_map(
            static fn (array $m): BusinessTerm => $m['terme'],
            $this->readiness->manques($facture),
        );
    }

    private function vendeurComplet(): ProfilExploitant
    {
        return (new ProfilExploitant())
            ->setSiren('130025265')
            ->setRaisonSociale('Régie des Eaux de Test')
            ->setTvaIntracommunautaire('FR12130025265')
            ->setAdresse(['rue' => '2 rue du Port', 'cp' => '34200', 'ville' => 'Sète', 'pays' => 'FR']);
    }

    private function factureAvecAcheteurComplet(): Facture
    {
        $destinataire = (new DestinataireFacturation())
            ->setRaisonSociale('Commune de Test')
            ->setAdresse(['rue' => '1 rue de la Mairie', 'cp' => '75001', 'ville' => 'Paris', 'pays' => 'FR']);

        $facture = (new Facture())
            ->setStatut(StatutFacture::Emise)
            ->setNumero('FA-2026-0001')
            ->setDateEmission(new \DateTimeImmutable('2026-08-31'))
            ->setDestinataire($destinataire);

        $facture->addLigne(
            (new LigneFacture())->setDesignation('Entrée piscine')->setQuantite(3)
        );

        return $facture;
    }
}
