<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Organisation\Entity\Etablissement;
use App\DataFixtures\SocleFixtures;
use Symfony\Component\Uid\Uuid;
use App\Acces\Entity\Passage;
use App\Acces\Enum\ResultatPassage;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CONTROLE MANUEL D'UN BILLET, la ou il n'y a pas de materiel (D86, D87).
 *
 * Demande par Maxime : « certains n'ont pas de controle d'acces, mais le billet pourra etre quand
 * meme valide par un controle manuel ». Avant cette route, les deux entrees existantes exigeaient
 * toutes deux une reference d'equipement — un billet vendu sur un site sans tourniquet etait donc
 * invendable en pratique.
 *
 * CE QUE CES TESTS VERIFIENT EN PLUS DU RESULTAT : que la route ne traverse AUCUNE des regles de
 * porte. Pas d'equipement, pas de zone declaree, pas de jauge. C'est tout l'objet de l'extraction,
 * et c'est ce qui se perdrait le plus silencieusement si quelqu'un rebranchait cette route sur
 * `ValidationPassageHandler` en croyant supprimer une duplication.
 */
final class ControleBilletTest extends AccesApiTestCase
{
    public function testUnBilletValideEstAccepteSansAucunEquipementNiZone(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/controle-billet', $entete + [
            'json' => ['identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT],
        ]);

        self::assertResponseIsSuccessful();
        $reponse = $client->getResponse()->toArray();

        self::assertSame('valide', $reponse['resultat']);
        self::assertTrue($reponse['consomme'], 'la decision de Maxime est que le scan consomme, et qu il le dise');
        self::assertNull($reponse['codeMotif']);
        self::assertNull($reponse['dejaControleLe']);
    }

    /**
     * LE PASSAGE EST ECRIT, ET IL N'A PAS DE LIEU.
     *
     * Sans trace, l'historique aurait un trou exactement la ou il n'y a pas de materiel. Avec une
     * zone inventee, il dirait qu'un porteur a franchi une porte qu'il n'a pas franchie. L'absence
     * d'espace est donc la reponse juste — et elle doit etre VERIFIEE, parce qu'une colonne
     * redevenue NOT NULL ferait echouer l'ecriture la ou personne ne regarde.
     */
    public function testLeControleLaisseUnPassageSansEspaceEtRattacheAuSite(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = \count($em->getRepository(Passage::class)->findAll());

        $client->request('POST', '/api/acces/controle-billet', $entete + [
            'json' => ['identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $passages = $em->getRepository(Passage::class)->findAll();
        self::assertCount($avant + 1, $passages, 'un controle manuel doit laisser une trace');

        $dernier = null;
        foreach ($passages as $passage) {
            if ($dernier === null || $passage->getHorodatage() >= $dernier->getHorodatage()) {
                $dernier = $passage;
            }
        }

        self::assertInstanceOf(Passage::class, $dernier);
        self::assertNull($dernier->getEspace(), 'un controle manuel ne franchit aucune porte');
        self::assertNull($dernier->getEquipement());
        self::assertSame($idA, (string) $dernier->getEtablissement()?->getId(), 'sans espace, l etablissement doit etre pose explicitement — sinon la ligne echappe au cloisonnement');
    }

    /**
     * LE SECOND SCAN D'UNE ENTREE UNIQUE REND `deja_consomme` AVEC SON HEURE — c'est la decision de
     * Maxime, et c'est la seule branche qui la rend utile.
     *
     * « Il consomme et il le dit » ne vaut que si l'agent peut decider ensuite : « il y a trente
     * secondes » (il a scanne deux fois) et « ce matin » (quelqu'un d'autre avec le meme billet)
     * appellent des gestes opposes. D'ou `dejaControleLe` A COTE du libelle, jamais dedans — un
     * ecran qui devrait analyser une phrase francaise pour retrouver l'heure casserait le jour ou
     * l'on corrige une faute d'orthographe.
     *
     * La fixture porte une carte a credit, pas une entree unique : ce test cree donc son propre
     * billet sans credit. Sans lui, cette branche entiere resterait verte sans etre executee.
     */
    public function testUnBilletSansCreditNePasseQuUneFoisEtDitQuand(): void
    {
        [$client, $entete] = $this->adminSurA();

        $identifiant = $this->creerBilletEntreeUnique();

        $client->request('POST', '/api/acces/controle-billet', $entete + ['json' => ['identifiantSupport' => $identifiant]]);
        self::assertResponseIsSuccessful();
        $premier = $client->getResponse()->toArray();
        self::assertSame('valide', $premier['resultat'], 'temoin positif : le premier controle doit passer, sinon le second ne prouve rien');
        self::assertNull($premier['credit'], 'une entree unique n a pas de notion de credit — null veut dire cela, et rien d autre');

        $client->request('POST', '/api/acces/controle-billet', $entete + ['json' => ['identifiantSupport' => $identifiant]]);
        self::assertResponseIsSuccessful();
        $second = $client->getResponse()->toArray();

        self::assertSame('refuse', $second['resultat']);
        self::assertSame('deja_consomme', $second['codeMotif'], 'PAS credit_epuise : une carte a zero se recharge, un billet deja controle appelle une question');
        self::assertNotNull($second['dejaControleLe'], 'sans l heure, la decision « il consomme et il le dit » perd son sens');
        self::assertNotFalse(strtotime((string) $second['dejaControleLe']), 'l heure doit etre lisible par une machine, pas une phrase');
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

        $identifiant = 'UNIQ-' . substr((string) Uuid::v4(), 0, 8);
        $support = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($support);

        $em->persist((new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));
        $em->flush();

        return $identifiant;
    }

    public function testUnSupportInconnuEstRefuseSansFuiteDInformation(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/controle-billet', $entete + [
            'json' => ['identifiantSupport' => 'INCONNU-XXXX-0000'],
        ]);

        self::assertResponseIsSuccessful('un refus est une reponse, pas une erreur');
        $reponse = $client->getResponse()->toArray();

        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('droit_invalide', $reponse['codeMotif']);
    }

    public function testIdentifiantManquantEstRefuseEn422(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/controle-billet', $entete + ['json' => []]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * SANS ETABLISSEMENT ACTIF, ON REFUSE — ET CE N'EST PAS DE LA PRUDENCE DECORATIVE.
     *
     * `Passage::setEspace()` derive l'etablissement de l'espace ; ici l'espace est nul, donc la
     * derivation ne se fait pas. Ecrire sans etablissement produirait une ligne invisible au
     * cloisonnement, lisible par tous les sites — et une ligne sans etablissement ne se plaint
     * jamais.
     */
    public function testSansEtablissementActifLeControleEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Temoin positif : avec l'en-tete, la route repond.
        $client->request('POST', '/api/acces/controle-billet', $entete + [
            'json' => ['identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT],
        ]);
        self::assertResponseIsSuccessful();

        $sans = $entete;
        unset($sans['headers'][ContexteEtablissement::HEADER]);
        $client->request('POST', '/api/acces/controle-billet', $sans + [
            'json' => ['identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT],
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
