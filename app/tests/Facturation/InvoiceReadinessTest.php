<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Facturation\Einvoicing\BusinessTerm;
use App\Facturation\Einvoicing\InvoiceReadiness;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\StatutFacture;
use PHPUnit\Framework\TestCase;

/**
 * `InvoiceReadiness` — ce qui manque pour émettre au format européen EN 16931.
 *
 * ⚠ **CE CONTRÔLE REFUSE TOUT AUJOURD'HUI, ET C'EST JUSTE.** L'identité du vendeur n'existe pas
 * dans le modèle de données : `ProfilExploitant` ne porte qu'un SIREN, `Etablissement` aucune
 * adresse. Un contrôle qui refuse tout est pourtant le plus difficile à tester : ses refus sont
 * vrais pour la mauvaise raison dès qu'on se trompe quelque part.
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
     * ⚠ **CE TEST DOIT DEVENIR ROUGE LE JOUR OÙ QUELQU'UN MODÉLISE LE VENDEUR — c'est son but.**
     *
     * `ProfilExploitant` ne porte qu'un SIREN, `Etablissement` aucune adresse. Tant que c'est vrai,
     * `InvoiceReadiness` déclare ces termes absents sans même les chercher — on ne peut pas lire un
     * champ qui n'existe pas.
     *
     * Le jour où les champs arrivent, ce test échouera et forcera à remplacer la déclaration par
     * une vraie lecture. Sans lui, le contrôle continuerait de réclamer des champs désormais
     * remplis, et personne ne le saurait avant de lire le rapport.
     */
    public function testLeVendeurEstDeclareAbsentTantQuIlNEstPasModelise(): void
    {
        $termes = $this->termes($this->factureAvecAcheteurComplet());

        foreach ([
            BusinessTerm::SellerName,
            BusinessTerm::SellerVatIdentifier,
            BusinessTerm::SellerStreet,
            BusinessTerm::SellerPostcode,
            BusinessTerm::SellerCity,
            BusinessTerm::SellerCountryCode,
        ] as $terme) {
            self::assertContains($terme, $termes, sprintf(
                '%s n’est pas modélisé — si ce test échoue, les champs existent enfin : '
                . 'remplace la déclaration par une lecture dans InvoiceReadiness.',
                $terme->libelle(),
            ));
        }
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
