<?php

declare(strict_types=1);

namespace App\Tests\Website;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\SocleApiTestCase;
use App\Website\Config\TradeFallback;
use App\Website\Entity\Trade;
use App\Website\Entity\TradeActivity;
use App\Website\Enum\PublicationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les deux états de la base doivent rendre exactement la même chose.
 *
 * Le site sert les LIGNES de `website_trade` dès qu'il en existe une, et retombe sur les constantes
 * de {@see TradeFallback} tant qu'il n'y en a aucune. Les deux chemins sont du code différent :
 * `versMetier()` d'un côté, `depuisLaLigne()` de l'autre.
 *
 * ⚠ **MESURER UN SEUL DES DEUX ÉTATS NE PROUVE RIEN.**
 *
 * Ne mesurer que le repli attesterait qu'on n'a rien cassé EN N'AYANT RIEN BRANCHÉ : c'est le chemin
 * qui existait déjà. Ne mesurer que l'état semé laisserait passer une régression du repli — celui
 * que sert toute base neuve, y compris une première mise en production.
 *
 * ⚠ **ET « IDENTIQUE » PEUT AUSSI VOULOIR DIRE « LA BASE EST IGNORÉE ».** C'est arrivé pendant
 * l'écriture de ce lot : deux états identiques parce que la mutation censée les séparer n'avait pas
 * été appliquée. D'où {@see self::testLaBasculeLitVraimentLesLignes()}, qui sème un nom DIFFÉRENT et
 * exige que le rendu change. Sans lui, les tests ci-dessus resteraient verts sur un site qui
 * n'ouvrirait jamais la table.
 */
final class FallbackParityTest extends SocleApiTestCase
{
    /** Les surfaces que le référentiel ne doit pas modifier. */
    private const SURFACES = [
        '/',
        '/metiers',
        '/metiers/piscine',
        '/metiers/sport',
        '/metiers/padel',
        '/metiers/patinoire',
        '/metiers/musee',
        '/sitemap.xml',
        '/llms.txt',
    ];

    public function testLesNeufSurfacesSontIdentiquesDansLesDeuxEtats(): void
    {
        $client = static::createClient();

        $repli = $this->rendre($client);
        $this->semerLesCinq();
        $lignes = $this->rendre($client);

        foreach (self::SURFACES as $chemin) {
            self::assertSame(
                $repli[$chemin],
                $lignes[$chemin],
                sprintf(
                    '%s ne rend pas la même chose selon que la base porte des lignes ou non. '
                    .'Le référentiel devait changer la SOURCE, pas le rendu.',
                    $chemin,
                ),
            );
        }
    }

    /**
     * ⚠ **LE TÉMOIN DE L'INSTRUMENT, et il vaut plus que le test ci-dessus.**
     *
     * Deux rendus identiques prouvent la non-régression — OU l'absence totale de branchement. Ici on
     * sème un nom qui n'est pas celui du repli : le rendu DOIT alors différer. S'il ne diffère pas,
     * c'est que le site n'ouvre pas la table, et le test précédent était vert pour une raison qui
     * n'a rien à voir avec ce qu'il annonce.
     */
    public function testLaBasculeLitVraimentLesLignes(): void
    {
        $client = static::createClient();

        $repli = (string) $client->request('GET', '/metiers')->getContent();
        self::assertStringContainsString('Piscines et centres aquatiques', $repli);

        $this->semerLesCinq('Piscines RENOMMÉES EN BASE');

        $lignes = (string) $client->request('GET', '/metiers')->getContent();

        self::assertStringContainsString(
            'Piscines RENOMMÉES EN BASE',
            $lignes,
            'Le site doit servir le nom de la LIGNE, pas celui de la constante.',
        );
        self::assertStringNotContainsString(
            'Piscines et centres aquatiques',
            $lignes,
            'Le repli est tout-ou-rien : dès qu’une ligne existe, les constantes ne sortent plus. '
            .'Un nom de constante encore visible signalerait une fusion champ par champ, qui rendrait '
            .'une base à moitié semée indiscernable d’une base saine.',
        );
    }

    /**
     * ⚠ **L'ÉTAT AMBIGU QUE LE TOUT-OU-RIEN NE PEUT PAS VOIR : quatre lignes sur cinq.**
     *
     * Zéro ligne se distingue de cinq ; quatre ne se distingue de cinq par aucun mécanisme du site.
     * Il n'y a rien à corriger là — le repli PAR CHAMP serait pire, il masquerait la ligne cassée —
     * mais il y a quelque chose à figer : ce qui arrive alors doit être une PAGE ABSENTE (404), pas
     * une page à moitié remplie, et surtout pas la constante servie en douce.
     */
    public function testUneBaseAMoitieSemeeNeRessuscitePasLaConstante(): void
    {
        $client = static::createClient();

        $this->semerLesCinq(null, 'musee');

        $client->request('GET', '/metiers/musee');
        self::assertSame(
            404,
            $client->getResponse()->getStatusCode(),
            'Un métier dont la ligne manque doit être ABSENT, pas servi depuis la constante : '
            .'sinon personne ne saurait jamais que la ligne manque.',
        );

        $liste = (string) $client->request('GET', '/metiers')->getContent();
        self::assertStringNotContainsString('/metiers/musee', $liste);
        self::assertStringContainsString('/metiers/piscine', $liste, 'Les quatre autres restent servis.');
    }

    // ---------------------------------------------------------------- montage

    /** @return array<string, string> */
    private function rendre(Client $client): array
    {
        $rendus = [];

        foreach (self::SURFACES as $chemin) {
            $client->request('GET', $chemin);

            // ⚠ Une page d'erreur rendue deux fois est identique elle aussi : sans ce contrôle, un
            //   500 des deux côtés passerait très bien pour une non-régression.
            self::assertSame(200, $client->getResponse()->getStatusCode(), $chemin.' ne rend pas 200.');

            $corps = (string) $client->getResponse()->getContent();
            self::assertNotSame('', $corps, $chemin.' rend un corps vide.');

            $rendus[$chemin] = $corps;
        }

        return $rendus;
    }

    /**
     * ⚠ **ON NE SÈME PAS DANS LES FIXTURES COMMUNES**, et c'est délibéré : toute la suite basculerait
     * sur le chemin des lignes, et le repli ne serait plus jamais parcouru — un vert obtenu en ne
     * testant qu'une moitié.
     *
     * @param string|null $nomDeLaPiscine un nom différent du repli, pour prouver que la base est lue
     * @param string|null $sauf           un code à ne PAS semer, pour l'état à moitié semé
     */
    private function semerLesCinq(?string $nomDeLaPiscine = null, ?string $sauf = null): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $semees = 0;

        foreach (TradeFallback::entries() as $entree) {
            if ($entree['code'] === $sauf) {
                continue;
            }

            $ligne = (new Trade())
                ->setCode($entree['code'])
                ->setSlug($entree['slug'])
                ->setName('piscine' === $entree['code'] && null !== $nomDeLaPiscine ? $nomDeLaPiscine : $entree['name'])
                ->setSearchTitle($entree['searchTitle'])
                ->setLead($entree['lead'])
                ->setPosition($entree['position'])
                ->setStatus(PublicationStatus::Published);

            $rang = 0;

            foreach ($entree['activities'] as $activite) {
                $rang += 10;
                $ligne->addActivity((new TradeActivity())->setActivity($activite)->setPosition($rang));
            }

            $em->persist($ligne);
            ++$semees;
        }

        $em->flush();

        // Témoin : sans lui, une base restée vide ferait passer tout ce test pour l'état semé.
        self::assertCount(
            $semees,
            $em->getRepository(Trade::class)->findAll(),
            'Les lignes doivent être réellement en base avant de mesurer quoi que ce soit.',
        );
        self::assertSame(null === $sauf ? 5 : 4, $semees);
    }
}
