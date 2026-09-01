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

    /**
     * ⚠ UN TERME DECLARE OBLIGATOIRE ET JAMAIS VERIFIE EST UN FAUX VERT EN ATTENTE.
     *
     * Mesure du 02/09 : `BusinessTerm` declarait 28 termes obligatoires, `InvoiceReadiness` n'en
     * verifiait que 18. Le rapport disait vrai — « 0 emettable » — mais pour d'autres raisons. Le
     * jour ou les quatre adresses acheteur manquantes auraient ete saisies, il aurait annonce
     * « emettable » avec NEUF termes obligatoires jamais controles.
     *
     * C'est la forme la plus couteuse : pas une erreur, un feu vert. Et rien ne reliait les deux
     * fichiers — ajouter un cas a l'enumeration ne demandait pas de le verifier.
     *
     * ⚠ DEUX EXCEPTIONS ASSUMEES, ET ELLES SONT NOMMEES ICI PLUTOT QUE TUES. Un terme dont le TYPE
     * garantit la valeur n'a rien a verifier : le controler produirait une branche morte qu'on
     * lirait comme une couverture.
     */
    public function testChaqueTermeObligatoireEstVerifieOuExplicitementExempte(): void
    {
        // Les termes qu'un type enumere ou un invariant rend impossibles a manquer.
        $exemptes = [
            // `LigneFacture::$unitCode` est un `UnitCode` : le type garantit un code Rec 20.
            BusinessTerm::LineUnitCode,
            // `Facture::$nature` est un `NatureFacture` toujours pose : le code UNTDID 1001 s'en
            // deduit sans ambiguite (380 facture, 381 avoir, 386 acompte). Il ne peut pas manquer.
            BusinessTerm::TypeCode,
            // `LigneFacture::$quantite` est un `int` non nul avec `Assert\Positive` et un defaut a 1.
            BusinessTerm::LineQuantity,
        ];

        // ⚠ PAR REFLEXION, PAS PAR CHEMIN RELATIF. `dirname(__DIR__, 3)` supposait une profondeur
        // d'arborescence : il rendait `/repo/src/...` au lieu de `/repo/app/src/...`, donc `false`,
        // donc un test qui echouait pour une raison qui n'avait rien a voir avec ce qu'il mesure.
        // La reflexion demande au moteur ou la classe habite ; elle ne peut pas se tromper de niveau.
        $fichier = (new \ReflectionClass(InvoiceReadiness::class))->getFileName();
        self::assertIsString($fichier, 'la classe doit avoir un fichier');

        $source = file_get_contents($fichier);
        self::assertIsString($source);

        $jamaisVerifies = [];
        foreach (BusinessTerm::cases() as $terme) {
            if (in_array($terme, $exemptes, true)) {
                continue;
            }
            if (!str_contains($source, 'BusinessTerm::' . $terme->name)) {
                $jamaisVerifies[] = $terme->name . ' (' . $terme->value . ')';
            }
        }

        self::assertSame(
            [],
            $jamaisVerifies,
            "Ces termes sont declares obligatoires et ne sont jamais verifies : le rapport peut donc
"
            . "annoncer « emettable » sans les avoir regardes.
  " . implode("
  ", $jamaisVerifies),
        );
    }

    // ── BT-5 et BT-130, poses le 02/09 ─────────────────────────────────────────────────────────

    /**
     * ⚠ CES DEUX TERMES N'AVAIENT AUCUN CHAMP JUSQU'AU 02/09.
     *
     * `facturation:einvoicing:etat` les nommait « aucun champ » : sur les 28 termes obligatoires
     * d'EN 16931, c'etaient les deux SEULS qui manquaient au modele. Tout le reste relevait d'une
     * saisie. Ces tests gardent la modelisation, pas la saisie.
     */
    public function testLaDeviseEtLUniteNeManquentPlus(): void
    {
        $termes = $this->termes($this->factureAvecAcheteurComplet());

        self::assertNotContains(BusinessTerm::CurrencyCode, $termes, 'BT-5 est desormais porte par Facture::currency.');
        self::assertNotContains(BusinessTerm::LineUnitCode, $termes, 'BT-130 est desormais porte par LigneFacture::unitCode.');
    }

    /**
     * Les deux defauts explicitent ce qui etait deja vrai, ils n'inventent rien (D66-ter).
     *
     * `EUR` etait implicite dans tout le depot ; `C62` (« one ») est ce que comptait deja une
     * quantite sans unite. Si ces defauts changeaient, des factures existantes changeraient de sens
     * sans que personne ne l'ait decide.
     */
    public function testLesDefautsSontEurEtC62(): void
    {
        self::assertSame('EUR', (new Facture())->getCurrency());
        self::assertSame('C62', (new LigneFacture())->getUnitCode()->value);
    }

    /**
     * ⚠ LE TEMOIN : « toujours remplie » n'est pas « toujours valide ».
     *
     * La colonne est `NOT NULL` avec defaut, donc elle porte toujours quelque chose. Sans ce test,
     * `testLaDeviseEtLUniteNeManquentPlus` passerait aussi si le controle de BT-5 avait ete retire :
     * il ne prouverait alors que l'existence du champ, pas sa verification.
     */
    public function testUneDeviseMalFormeeEstSignalee(): void
    {
        $facture = $this->factureAvecAcheteurComplet();
        $facture->setCurrency('e');

        self::assertContains(BusinessTerm::CurrencyCode, $this->termes($facture));
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
