<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Tests\SocleApiTestCase;
use App\Website\Entity\ContentBlock;
use App\Website\Service\MetierCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-12 — les pages métiers, et ce qu'elles ont le droit d'affirmer.
 *
 * ⚠ **CE QUI EST VÉRIFIÉ ICI N'EST PAS QUE LA PAGE RÉPOND.** Une page métier qui décrirait un
 * préréglage inventé se démonterait en démonstration — le seul moment où un prospect la teste. Les
 * tests confrontent donc ce que la page affiche à ce que le PRODUIT prérègle, dans les deux sens :
 * ce qu'elle doit montrer, et ce qu'elle ne doit pas.
 */
final class WebsiteMetiersTest extends SocleApiTestCase
{
    /** Les cinq métiers du produit, et EUX SEULS. */
    public function testLaListeDesMetiersEstCelleDuProduit(): void
    {
        $this->sauterSiRouteAbsente('website_metiers');

        $client = static::createClient();
        $html = (string) $client->request('GET', '/metiers')->getContent();

        self::assertResponseIsSuccessful();

        /*
         * ⚠ COMPARAISON D'ENSEMBLE, PAS CINQ PRÉSENCES.
         *
         * Ce test promettait « et eux seuls » depuis toujours et vérifiait cinq
         * `assertStringContainsString` : une page qui aurait listé cinquante métiers — repli cassé,
         * ligne de trop en base, doublon — passait au vert. Le mot « seuls » n'était porté par
         * aucune assertion. Depuis que la liste vient de la BASE, ce n'est plus théorique.
         */
        self::assertSame(
            ['musee', 'padel', 'patinoire', 'piscine', 'sport'],
            $this->metiersListes($html),
            'La page doit lister les cinq métiers du produit, et aucun autre.',
        );

        // Et chacun porte un nom : une liste de cinq liens vides serait « les cinq métiers » aussi.
        $noms = $this->nomsListes($html);

        /*
         * ⚠ SANS CE COMPTE, LA BOUCLE CI-DESSOUS N'ASSERTERAIT RIEN sur une liste vide : elle ne
         *   s'exécuterait pas, et le test passerait en n'ayant rien regardé. C'est le garde-fou
         *   de vacuité qui me l'a compté, pas moi qui l'ai vu.
         */
        self::assertCount(5, $noms, 'Les cinq liens doivent avoir été relus avant qu’on juge leurs noms.');

        foreach ($noms as $slug => $nom) {
            self::assertNotSame('', trim($nom), sprintf('le métier « %s » s\'affiche sans nom', $slug));
        }
    }

    /**
     * ⚠ **LA PAGE MONTRE LE PRÉRÉGLAGE RÉEL, ET LE TÉMOIN QUI COMPTE EST CE QU'ELLE N'AFFICHE PAS.**
     *
     * Le préréglage « piscine » porte les casiers et pas la boutique en ligne ; celui du musée
     * l'inverse. Une page qui listerait tous les modules pour tous les métiers passerait le premier
     * contrôle et raterait celui-ci — et c'est exactement la page qu'on écrit quand on se contente
     * de remplir un gabarit.
     */
    public function testChaqueMetierAffichSonProprePrereglage(): void
    {
        $this->sauterSiRouteAbsente('website_metier');

        $client = static::createClient();

        $piscine = (string) $client->request('GET', '/metiers/piscine')->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Casiers', $piscine);
        self::assertStringNotContainsString('Boutique en ligne', $piscine, 'le préréglage piscine ne la porte pas');

        $musee = (string) $client->request('GET', '/metiers/musee')->getContent();
        self::assertStringContainsString('Boutique en ligne', $musee);
        self::assertStringNotContainsString('Casiers', $musee, 'le préréglage musée ne les porte pas');
    }

    /**
     * Les spécificités affichées nomment des objets que le produit porte vraiment.
     *
     * Ce test ne peut pas prouver qu'une phrase est vraie ; il fige le fait qu'elle est là, et le
     * commentaire de `MetierCatalog` dit quelle entité chacune désigne. C'est ce qui permet à
     * quelqu'un d'ouvrir `Piscine\Entity\Poss` et de vérifier lui-même.
     */
    public function testLesSpecificitesSontAffichees(): void
    {
        $this->sauterSiRouteAbsente('website_metier');

        $client = static::createClient();

        self::assertStringContainsString('POSS', (string) $client->request('GET', '/metiers/piscine')->getContent());
        self::assertStringContainsString('affûtage', (string) $client->request('GET', '/metiers/patinoire')->getContent());
        self::assertStringContainsString('audioguide', (string) $client->request('GET', '/metiers/musee')->getContent());
    }

    /** Le texte long d'un métier s'affiche quand il est écrit, et son absence ne casse rien. */
    public function testLeTexteLongDunMetierSaffiche(): void
    {
        $this->sauterSiRouteAbsente('website_metier');

        $client = static::createClient();

        $sans = (string) $client->request('GET', '/metiers/padel')->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('padel', $sans);

        $this->bloc(MetierCatalog::cleDeBloc('padel'), ['html' => '<h2>Nos clubs</h2><p>Un texte rédigé.</p>']);

        $avec = (string) $client->request('GET', '/metiers/padel')->getContent();
        self::assertStringContainsString('<h2>Nos clubs</h2>', $avec);
    }

    /**
     * Un métier dont aucune ligne n'existe rend 404.
     *
     * ⚠ **LE SENS DE CE TEST A CHANGÉ AVEC LE RÉFÉRENTIEL, ET SON CODE D'ESSAI AUSSI.** Il disait
     * « un métier inventé » et interrogeait `/metiers/bowling` — or « bowling » est désormais
     * exactement un métier LÉGITIME qu'on crée en base sans déploiement, et
     * {@see SixthTradeTest} le fait. Le garder ici aurait laissé deux tests affirmer le contraire
     * l'un de l'autre, chacun vert dans son propre état de base.
     *
     * Ce qui est vérifié n'est pas « ce code n'est pas dans l'énumération » — c'est « aucune ligne
     * publiée ne le porte », qui est la seule question que le site se pose maintenant.
     */
    public function testUnMetierSansLigneRend404(): void
    {
        $this->sauterSiRouteAbsente('website_metier');

        $client = static::createClient();
        self::assertSame(404, $client->request('GET', '/metiers/nexiste-pas')->getStatusCode());
    }

    /** Le plan du site déclare les cinq pages — il en vérifiait DEUX. */
    public function testLePlanDuSiteDeclareLesMetiers(): void
    {
        $this->sauterSiRouteAbsente('website_sitemap');

        $client = static::createClient();
        $xml = (string) $client->request('GET', '/sitemap.xml')->getContent();

        self::assertStringContainsString('/metiers</loc>', $xml);

        preg_match_all('#<loc>[^<]*/metiers/([a-z0-9-]+)</loc>#', $xml, $trouve);
        $slugs = $trouve[1];
        sort($slugs);

        self::assertSame(
            ['musee', 'padel', 'patinoire', 'piscine', 'sport'],
            $slugs,
            'Une page absente du plan du site est une page que personne ne trouvera ; une page en '
            .'trop est un lien mort proposé aux moteurs.',
        );
    }

    /**
     * Les slugs listés par la page, triés et dédoublonnés.
     *
     * ⚠ On lit la page rendue, pas le service : un service juste et un gabarit qui n'en affiche que
     * la moitié donneraient un test vert et une page fausse.
     *
     * @return list<string>
     */
    private function metiersListes(string $html): array
    {
        preg_match_all('#href="/metiers/([a-z0-9-]+)"#', $html, $trouve);

        $slugs = array_values(array_unique($trouve[1]));
        sort($slugs);

        return $slugs;
    }

    /**
     * Le nom affiché pour chaque slug listé.
     *
     * @return array<string, string>
     */
    private function nomsListes(string $html): array
    {
        preg_match_all('#href="/metiers/([a-z0-9-]+)"[^>]*>([^<]*)<#', $html, $trouve, \PREG_SET_ORDER);

        $noms = [];

        foreach ($trouve as $paire) {
            $noms[$paire[1]] = $paire[2];
        }

        return $noms;
    }

    // ---------------------------------------------------------------- montage

    private function sauterSiRouteAbsente(string $nom): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        if (null === $routeur->getRouteCollection()->get($nom)) {
            self::markTestSkipped(sprintf('Route « %s » absente.', $nom));
        }
    }

    /** @param array<int|string, mixed> $valeur */
    private function bloc(string $cle, array $valeur): void
    {
        $bloc = (new ContentBlock($cle))->setValue($valeur, new \DateTimeImmutable());
        $this->em()->persist($bloc);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
