<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Fonctionnalite\Enum\EstablishmentActivity;
use App\Tests\SocleApiTestCase;
use App\Website\Config\TradeFallback;
use App\Website\Entity\Trade;
use App\Website\Entity\TradeActivity;
use App\Website\Enum\PublicationStatus;
use App\Website\Service\ContentBlocks;
use App\Website\Service\MetierCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Un métier qui n'existe QU'EN BASE, de bout en bout, sans déploiement.
 *
 * C'est l'objectif du lot. Aucun autre témoin ne le mesure : les autres partent des cinq métiers que
 * l'application connaît déjà, et un métier connu emprunte des chemins — son préréglage, son écran de
 * démonstration, ses spécificités — qu'un métier créé en base n'a pas.
 *
 * ⚠ **CE QUE CE TÉMOIN ATTRAPE, ET QU'AUCUN AUTRE NE VOIT.** `SiteBlocks` est une DÉCLARATION
 * statique — elle s'annonce « lisible sans conteneur », donc elle ne peut pas ouvrir la base. Tant
 * qu'elle ne connaissait que les cinq métiers du repli, un sixième créé en base avait sa page, son
 * entrée au plan du site et ses données structurées… et son corps de page était **inenregistrable** :
 * `ContentBlocks::enregistrer()` refusait une clé qu'aucune déclaration ne portait.
 *
 * Tout aurait marché sauf la seule chose que le parcours décrit — « écrire le texte, publier ». D'où
 * l'assertion qui porte l'étape : on ENREGISTRE, puis on RELIT.
 */
final class SixthTradeTest extends SocleApiTestCase
{
    private const CODE = 'bowling';

    public function testUnMetierCreeEnBaseAToutCeQuIlFaut(): void
    {
        $client = static::createClient();
        $this->semerLesCinqPlusUn();

        // ── la page existe, et porte SES textes ────────────────────────────────────────────────
        $client->request('GET', '/metiers/'.self::CODE);
        self::assertResponseIsSuccessful('Un métier publié en base doit avoir sa page.');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Logiciel de gestion pour bowling', $html);
        self::assertStringContainsString('Parties, pistes, ligues', $html);

        // ── il entre au plan du site ───────────────────────────────────────────────────────────
        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '/metiers/'.self::CODE,
            (string) $client->getResponse()->getContent(),
            'Une page que le plan du site ignore est une page que personne ne trouvera.',
        );

        // ── ET SON CORPS S'ENREGISTRE : l'assertion qui porte toute l'étape ────────────────────
        /** @var ContentBlocks $blocs */
        $blocs = static::getContainer()->get(ContentBlocks::class);
        $cle = MetierCatalog::cleDeBloc(self::CODE);

        $blocs->enregistrer($cle, ['html' => '<p>Le texte du bowling.</p>'], new \DateTimeImmutable());

        $declare = null;

        foreach ($blocs->pourLAdministration() as $ligne) {
            if ($ligne['key'] === $cle) {
                $declare = $ligne;

                break;
            }
        }

        self::assertNotNull($declare, sprintf('Le bloc « %s » doit être déclaré pour un métier créé en base.', $cle));
        self::assertSame(
            '<p>Le texte du bowling.</p>',
            $declare['value']['html'] ?? null,
            'Le texte enregistré doit se relire : sans ça, l’écran d’administration montrerait un bloc vide.',
        );

        // Et il est rendu sur la page, pas seulement stocké.
        $client->request('GET', '/metiers/'.self::CODE);
        self::assertStringContainsString('Le texte du bowling.', (string) $client->getResponse()->getContent());
    }

    /**
     * Le sixième métier n'a AUCUN préréglage : ses modules se déduisent de ses activités.
     *
     * C'est la seule voie possible pour un métier que l'application ne connaît pas — et c'est ce qui
     * rend « ajouter un métier sans déploiement » vrai jusqu'au bout, pas seulement pour le texte.
     */
    public function testSesModulesViennentDeSesActivites(): void
    {
        $client = static::createClient();
        $this->semerLesCinqPlusUn();

        $client->request('GET', '/metiers/'.self::CODE);
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        // `resource_booking` allume la réservation ; `equipment_rental` la location et les casiers.
        self::assertStringContainsString('Réservation de créneaux', $html);
        self::assertStringContainsString('Location de matériel', $html);
        self::assertStringContainsString('Casiers', $html);

        // ⚠ TÉMOIN NÉGATIF. Sans lui, un catalogue qui rendrait TOUS les modules passerait les trois
        //   assertions ci-dessus. Le POSS est une règle propre : aucune activité ne le sert, donc il
        //   ne doit apparaître sur aucune page de métier déduite des activités.
        self::assertStringNotContainsString(
            'POSS',
            $html,
            'Le POSS ne relève d’aucune activité : le suggérer serait vendre un module que rien ne justifie.',
        );
    }

    /**
     * ⚠ **LES CINQ AUSSI**, et pas seulement le sixième.
     *
     * Le repli est tout-ou-rien : ne semer QUE le bowling ferait basculer le site sur les lignes et
     * ferait disparaître les cinq autres. Les deux tests ci-dessus passeraient quand même — et ils
     * attesteraient d'un site amputé.
     */
    private function semerLesCinqPlusUn(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach (TradeFallback::entries() as $entree) {
            $ligne = (new Trade())
                ->setCode($entree['code'])
                ->setSlug($entree['slug'])
                ->setName($entree['name'])
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
        }

        $bowling = (new Trade())
            ->setCode(self::CODE)
            ->setSlug(self::CODE)
            ->setName('Bowlings')
            ->setSearchTitle('Logiciel de gestion pour bowling')
            ->setLead('Parties, pistes, ligues : un bowling se pilote à la partie autant qu’à l’heure.')
            ->setPosition(60)
            ->setStatus(PublicationStatus::Published);

        $rang = 0;

        foreach ([EstablishmentActivity::ResourceBooking, EstablishmentActivity::EquipmentRental] as $activite) {
            $rang += 10;
            $bowling->addActivity((new TradeActivity())->setActivity($activite)->setPosition($rang));
        }

        $em->persist($bowling);
        $em->flush();

        // Témoin : six lignes. Une base restée vide, ou à moitié semée, ferait mentir tout ce qui suit.
        self::assertCount(6, $em->getRepository(Trade::class)->findAll(), 'Les six lignes doivent être semées.');
    }
}
