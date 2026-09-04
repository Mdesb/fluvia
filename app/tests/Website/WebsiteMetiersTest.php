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
    /** Les cinq métiers du produit, et eux seuls. */
    public function testLaListeDesMetiersEstCelleDuProduit(): void
    {
        $this->sauterSiRouteAbsente('website_metiers');

        $client = static::createClient();
        $html = (string) $client->request('GET', '/metiers')->getContent();

        self::assertResponseIsSuccessful();

        foreach (['piscine', 'sport', 'padel', 'patinoire', 'musee'] as $metier) {
            self::assertStringContainsString('/metiers/'.$metier, $html, sprintf('le métier « %s » doit avoir sa page', $metier));
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

    /** Un métier inventé rend 404. */
    public function testUnMetierInconnuRend404(): void
    {
        $this->sauterSiRouteAbsente('website_metier');

        $client = static::createClient();
        self::assertSame(404, $client->request('GET', '/metiers/bowling')->getStatusCode());
    }

    /** Le plan du site déclare les cinq pages. */
    public function testLePlanDuSiteDeclareLesMetiers(): void
    {
        $this->sauterSiRouteAbsente('website_sitemap');

        $client = static::createClient();
        $xml = (string) $client->request('GET', '/sitemap.xml')->getContent();

        self::assertStringContainsString('/metiers</loc>', $xml);
        self::assertStringContainsString('/metiers/piscine</loc>', $xml);
        self::assertStringContainsString('/metiers/musee</loc>', $xml);
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
