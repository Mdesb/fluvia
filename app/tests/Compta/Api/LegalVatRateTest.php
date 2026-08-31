<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\HiddenLegalVatRate;
use App\Compta\Entity\LegalVatRate;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatRateCategory;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE CATALOGUE DES TAUX DE TVA LEGAUX, ET L'OEIL QUI MASQUE.
 *
 * Demande par Maxime : « les taux de TVA sont definis par les lois, un utilisateur n'a pas besoin de
 * le creer » — et, dans la meme phrase, « on devrait avoir un oeil pour masquer le taux ».
 *
 * CE QUE CES TESTS VERIFIENT AU-DELA DU RESULTAT :
 *
 *   — que le referentiel N'EST PAS une ressource exposee. Il est global a dessein, et une entite
 *     globale exposee ferait remonter le plafond du cliquet de cloisonnement ; c'est une VUE
 *     contextuelle qui le sert. Si quelqu'un rouvrait l'entite « pour simplifier », ce test le dit ;
 *   — que la reprise se fait cote SERVEUR. L'ecran n'envoie qu'un identifiant : le libelle, la
 *     valeur et le profil comptable sont lus a la source, donc le taux repris est exactement celui
 *     de la loi ;
 *   — que le masquage est cloisonne alors que le catalogue ne l'est pas. Les deux sont dans le meme
 *     lot et se confondent facilement.
 */
final class LegalVatRateTest extends ComptaApiTestCase
{
    public function testLeCatalogueRendLesTauxEnVigueurAvecLeurSourceEtLeursDates(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->semerTauxLegal('FR', VatRateCategory::Standard, '20.00');

        $client->request('GET', '/api/compta/vat-rate-catalog', $entete + ['query' => ['country' => 'FR']]);

        self::assertResponseIsSuccessful();
        $vue = $client->getResponse()->toArray();

        self::assertSame('FR', $vue['country']);
        self::assertNotEmpty($vue['rates'], 'temoin positif : sans entree, les assertions suivantes ne prouveraient rien');
        self::assertNotEmpty($vue['rates'][0]['source'], 'une table de taux legaux dont on ignore la source est une table d opinions');
        self::assertNotEmpty($vue['rates'][0]['validFrom'], 'sans date, le referentiel devient faux sur tout le passe au premier decret');
    }

    /**
     * ⚠ LE REFERENTIEL N'EST PAS EXPOSE, ET CE TEST EST LA POUR QUE CA RESTE VRAI.
     *
     * Un taux legal est un fait de droit : il ne se cloisonne pas. Une entite globale exposee ferait
     * remonter le plafond du cliquet de couverture de perimetre — et un cliquet desserre ne se
     * resserre jamais tout seul. Rouvrir `#[ApiResource]` dessus « pour simplifier l'ecran » se
     * verrait ici.
     */
    public function testLEntiteDuReferentielNEstPasUneRessourceExposee(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Temoin positif : la vue, elle, repond. Sans lui, un 404 pourrait venir d'une API muette.
        $client->request('GET', '/api/compta/vat-rate-catalog', $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/legal_vat_rates', $entete);
        self::assertResponseStatusCodeSame(404, 'le referentiel se consulte par la vue, pas en direct');
    }

    public function testUnPaysMalEcritEstRefuseAuLieuDeRendreUneListeVide(): void
    {
        [$client, $entete] = $this->adminSurA();

        // « France », « fra », « fr » rendraient tous zero taux — et zero se lit « aucun taux dans
        // ce pays », un mensonge tranquille. On refuse plutot que de laisser chercher ailleurs.
        $client->request('GET', '/api/compta/vat-rate-catalog', $entete + ['query' => ['country' => 'France']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReprendreUnTauxLeCreeDepuisLaSourceEtNeLeCreePasDeuxFois(): void
    {
        [$client, $entete] = $this->adminSurA();
        $legal = $this->semerTauxLegal('FR', VatRateCategory::Reduced, '10.00');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = \count($em->getRepository(TauxTva::class)->findAll());

        $client->request('POST', '/api/compta/vat-rate-catalog/adopt', $entete + [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['legalVatRateId' => (string) $legal->getId()],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $tous = $em->getRepository(TauxTva::class)->findAll();
        self::assertCount($avant + 1, $tous);

        $repris = null;
        foreach ($tous as $t) {
            if ((string) $t->getOrigineLegale()?->getId() === (string) $legal->getId()) {
                $repris = $t;
            }
        }

        self::assertInstanceOf(TauxTva::class, $repris, 'la filiation doit etre posee : c est elle qui dit d ou vient le taux');
        self::assertSame('10.00', $repris->getTaux(), 'la valeur est lue a la source, pas recopiee par l ecran');
        self::assertNotNull($repris->getProfilExploitant(), 'le profil comptable est resolu par le serveur');

        // Reprendre deux fois ne cree rien : l'idempotence porte sur la FILIATION, pas sur la
        // valeur — deux taux a 10 % peuvent etre deux choses differentes selon le pays.
        $client->request('POST', '/api/compta/vat-rate-catalog/adopt', $entete + [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['legalVatRateId' => (string) $legal->getId()],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        self::assertCount($avant + 1, $em->getRepository(TauxTva::class)->findAll());
    }

    public function testMasquerPuisDemasquerUnTauxLegal(): void
    {
        [$client, $entete] = $this->adminSurA();
        $legal = $this->semerTauxLegal('FR', VatRateCategory::SuperReduced, '2.10');

        $client->request('POST', '/api/compta/vat-rate-catalog/hide', $entete + [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['legalVatRateId' => (string) $legal->getId()],
        ]);
        self::assertResponseIsSuccessful();

        // La reponse EST le catalogue a jour : sans cela, l'ecran devrait relire, et afficherait
        // l'inverse de ce qui vient de se passer pendant la fenetre entre les deux requetes.
        $apresMasquage = $client->getResponse()->toArray();
        self::assertSame([(string) $legal->getId()], $apresMasquage['hidden']);

        // Masquer deux fois ne cree pas deux lignes : la contrainte d'unicite le dirait par un 500,
        // ce qui ferait croire a une panne pour un geste sans consequence.
        $client->request('POST', '/api/compta/vat-rate-catalog/hide', $entete + [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['legalVatRateId' => (string) $legal->getId()],
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getResponse()->toArray()['hidden']);

        // Et on demasque par le MEME identifiant : l'ecran n'a rien eu a retenir.
        $client->request('POST', '/api/compta/vat-rate-catalog/unhide', $entete + [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['legalVatRateId' => (string) $legal->getId()],
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getResponse()->toArray()['hidden']);
    }

    /**
     * ⚠ LE MASQUAGE EST CLOISONNE, LE CATALOGUE NE L'EST PAS — et cette difference se verifie.
     */
    public function testLeMasquageDUnAutreProfilNEstPasVisible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $legal = $this->semerTauxLegal('BE', VatRateCategory::Standard, '21.00');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $autreEtab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $autreEtab, 'le jeu de fixtures doit porter un second etablissement');

        $autre = (new ProfilExploitant())->setEtablissementPrincipal($autreEtab);
        $em->persist($autre);
        $em->persist((new HiddenLegalVatRate())->setProfilExploitant($autre)->setLegalVatRate($legal));
        $em->flush();

        $client->request('GET', '/api/compta/vat-rate-catalog', $entete + ['query' => ['country' => 'BE']]);
        self::assertResponseIsSuccessful();
        $vue = $client->getResponse()->toArray();

        // Temoin positif : le catalogue rend bien l'entree belge. Sans lui, le zero ci-dessous
        // pourrait etre un zero par construction — une liste vide pour une raison sans rapport.
        self::assertNotEmpty($vue['rates']);
        self::assertCount(0, $vue['hidden'], 'ce que le voisin masque ne nous regarde pas');
    }

    private function semerTauxLegal(string $pays, VatRateCategory $categorie, string $taux): LegalVatRate
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $entree = (new LegalVatRate())
            ->setCountry($pays)
            ->setCategory($categorie)
            ->setRate($taux)
            ->setLabel('Taux d epreuve')
            ->setValidFrom(new \DateTimeImmutable('2014-01-01'))
            ->setSource('Jeu de test');

        $em->persist($entree);
        $em->flush();

        return $entree;
    }
}
