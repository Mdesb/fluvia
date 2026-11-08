<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Passage;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LE CONTRÔLE MANUEL PENDANT UNE COUPURE (#338) : une liste locale sans identifiant en clair, puis la
 * remontée des contrôles, une seule fois, avec les conflits dits.
 */
final class OfflineTicketCheckTest extends AccesApiTestCase
{
    /**
     * La liste du site A contient le billet de A — sous forme d'empreinte seulement — et celle du
     * site B ne le contient pas. Deux clients, un témoin.
     */
    public function testLaListeLocaleNeContientQueDesEmpreintesDuSiteActif(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('GET', '/api/acces/controle-billet/liste-locale', $entete);
        self::assertResponseIsSuccessful();
        $brut = $reponse->getContent();
        $liste = $reponse->toArray();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $liste['sel']);
        $attendue = hash('sha256', $liste['sel'] . AccesFixtures::SUPPORT_IDENTIFIANT);
        self::assertContains($attendue, array_column($liste['entrees'], 'empreinte'), 'témoin positif : le billet du site est dans sa liste');
        self::assertStringNotContainsString(AccesFixtures::SUPPORT_IDENTIFIANT, $brut, 'aucun identifiant en clair ne quitte le serveur');

        $surB = $entete;
        $surB['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $listeB = $client->request('GET', '/api/acces/controle-billet/liste-locale', $surB)->toArray();
        self::assertResponseIsSuccessful();
        $empreinteB = hash('sha256', $listeB['sel'] . AccesFixtures::SUPPORT_IDENTIFIANT);
        self::assertNotContains($empreinteB, array_column($listeB['entrees'], 'empreinte'), 'le billet de A n est pas dans la liste de B');
    }

    /** Le sel change à chaque liste : deux copies de la base du navigateur ne se recoupent pas. */
    public function testLaPageSuivanteExigeLeSelDeLaPremiere(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/acces/controle-billet/liste-locale?page=2', $entete);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * LE TÉMOIN DU CÔTÉ ACCÈS : un contrôle admis hors ligne, remonté deux fois, est UN passage ; et
     * le billet consommé hors ligne est bien consommé en ligne ensuite.
     */
    public function testUnControleHorsLigneRejoueDeuxFoisNeFaitQuUnPassage(): void
    {
        [$client, $entete] = $this->adminSurA();
        $identifiant = $this->creerBilletEntreeUnique();

        $lot = ['controles' => [[
            'cleIdempotence' => (string) Uuid::v4(),
            'identifiantSupport' => $identifiant,
            'horodatage' => (new \DateTimeImmutable('-10 minutes'))->format(\DATE_ATOM),
            'verdictLocal' => 'valide',
        ]]];

        $premier = $client->request('POST', '/api/acces/controle-billet/lot', $entete + ['json' => $lot])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('accepte', $premier['resultats'][0]['statut']);
        self::assertFalse($premier['resultats'][0]['enConflit']);

        $second = $client->request('POST', '/api/acces/controle-billet/lot', $entete + ['json' => $lot])->toArray();
        self::assertSame('doublon', $second['resultats'][0]['statut']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $passages = $em->getRepository(Passage::class)->findBy(['cleIdempotence' => Uuid::fromString($lot['controles'][0]['cleIdempotence'])]);
        self::assertCount(1, $passages);
        self::assertTrue($passages[0]->isOrigineHorsLigne());
        self::assertSame(ResultatPassage::Valide, $passages[0]->getResultat());
        self::assertNull($passages[0]->getEspace());

        $enLigne = $client->request('POST', '/api/acces/controle-billet', $entete + ['json' => ['identifiantSupport' => $identifiant]])->toArray();
        self::assertSame('deja_consomme', $enLigne['codeMotif'], 'l entrée faite hors ligne compte en ligne');
    }

    /**
     * UN BILLET CONSOMMÉ EN LIGNE PUIS ADMIS HORS LIGNE : le passage est enregistré (la personne est
     * entrée), et marqué EN CONFLIT pour qu'un responsable le voie.
     */
    public function testUneEntreeUniqueDejaConsommeeEstRemonteeEnConflit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $identifiant = $this->creerBilletEntreeUnique();

        $client->request('POST', '/api/acces/controle-billet', $entete + ['json' => ['identifiantSupport' => $identifiant]]);
        self::assertResponseIsSuccessful();

        $reponse = $client->request('POST', '/api/acces/controle-billet/lot', $entete + ['json' => ['controles' => [[
            'cleIdempotence' => (string) Uuid::v4(),
            'identifiantSupport' => $identifiant,
            'horodatage' => (new \DateTimeImmutable('+1 minute'))->format(\DATE_ATOM),
            'verdictLocal' => 'valide',
        ]]]])->toArray();

        self::assertSame('accepte', $reponse['resultats'][0]['statut']);
        self::assertTrue($reponse['resultats'][0]['enConflit']);
    }

    /**
     * La clé d'un contrôle du site A, rejouée depuis le site B, n'y est pas un « doublon » : la
     * réponse dirait alors quelque chose d'un passage de A. Témoin : sur A, la même clé est un doublon.
     */
    public function testUneCleDUnAutreSiteNEstPasUnDoublonEtNeDitRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $lot = ['controles' => [[
            'cleIdempotence' => (string) Uuid::v4(),
            'identifiantSupport' => $this->creerBilletEntreeUnique(),
            'horodatage' => (new \DateTimeImmutable('-1 minute'))->format(\DATE_ATOM),
            'verdictLocal' => 'valide',
        ]]];
        $client->request('POST', '/api/acces/controle-billet/lot', $entete + ['json' => $lot]);
        self::assertResponseIsSuccessful();

        $surB = $entete;
        $surB['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $depuisB = $client->request('POST', '/api/acces/controle-billet/lot', $surB + ['json' => $lot])->toArray();
        self::assertSame('rejete', $depuisB['resultats'][0]['statut']);
        self::assertSame('cle_deja_utilisee', $depuisB['resultats'][0]['motif']);

        $depuisA = $client->request('POST', '/api/acces/controle-billet/lot', $entete + ['json' => $lot])->toArray();
        self::assertSame('doublon', $depuisA['resultats'][0]['statut'], 'témoin : sur son site, la même clé est un doublon');
    }

    /** Une clé illisible n'est jamais remplacée par une neuve : sinon chaque renvoi créerait un passage. */
    public function testUneCleIllisibleEstRejeteeSansPassage(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/acces/controle-billet/lot', $entete + ['json' => ['controles' => [[
            'cleIdempotence' => 'pas-une-cle',
            'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            'horodatage' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'verdictLocal' => 'valide',
        ]]]])->toArray();

        self::assertSame('rejete', $reponse['resultats'][0]['statut']);
    }

    private function creerBilletEntreeUnique(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::Billet)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($etab);
        $em->persist($droit);

        $identifiant = 'HL-' . substr((string) Uuid::v4(), 0, 8);
        $support = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($support);

        $em->persist((new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));
        $em->flush();

        return $identifiant;
    }
}
