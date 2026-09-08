<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\DataFixtures\SocleFixtures;
use App\Tests\SocleApiTestCase;
use App\Website\Entity\Trade;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'administration du référentiel des métiers, vue par HTTP.
 *
 * ⚠ **C'EST CET ÉCRAN QUI REND LE LOT VRAI.** Tout le reste rend un métier créé en base pleinement
 * servi par le site. Sans ces opérations, la seule façon de le créer resterait une requête SQL à la
 * main : « ajouter un métier sans déploiement » serait vrai pour la machine et faux pour la personne.
 *
 * Les refus comptent plus que les acceptations, et pour la raison habituelle : un écran qui accepte
 * tout produit des pages cassées APRÈS l'enregistrement, loin de celui qui a écrit, et sans que rien
 * n'échoue.
 */
final class EditorTradeApiTest extends SocleApiTestCase
{
    /**
     * L'en-tête qu'un PATCH exige ici.
     *
     * ⚠ Il passe par `commeEditeur()` et non par un second tableau : l'union `+` de PHP GARDE LA
     * CLÉ DE GAUCHE. Un `'headers' => [...]` ajouté à droite était donc purement ignoré, la requête
     * partait en `ld+json`, et le serveur répondait 415 — trois tests rouges pour une raison qui
     * n'avait rien à voir avec ce qu'ils vérifient.
     */
    private const FUSION = ['Content-Type' => 'application/merge-patch+json'];

    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /**
     * Le chemin nominal, de bout en bout : on crée « bowling » et le site le sert.
     *
     * ⚠ **LES MODULES SONT DANS LA RÉPONSE, ET ILS N'ÉTAIENT PAS DANS LA REQUÊTE.** C'est ce qui
     * rend l'écran honnête : on coche ce que l'établissement FAIT, et on voit tout de suite ce que le
     * produit en conclut. Une réponse qui renverrait ce qu'on a envoyé afficherait une liste vide
     * juste après l'enregistrement, et la vraie au rechargement.
     */
    public function testUnMetierCreeParLecranEstServiParLeSite(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/trades');

        $client = static::createClient();
        $reponse = $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + [
            'json' => [
                'code' => 'bowling',
                'name' => 'Bowlings',
                'searchTitle' => 'Logiciel de gestion pour bowling',
                'lead' => 'Parties, pistes, ligues.',
                'position' => 60,
                'status' => 'published',
                'activities' => ['resource_booking', 'equipment_rental'],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('bowling', $reponse['slug'], 'à défaut d’adresse fournie, c’est le code qui sert.');
        self::assertFalse($reponse['builtIn'], 'un métier créé en base ne porte aucun préréglage.');

        // Déduits des activités, jamais saisis.
        self::assertContains('Réservation de créneaux', $reponse['modules']);
        self::assertContains('Casiers', $reponse['modules']);
        self::assertNotContains('POSS', $reponse['modules'], 'aucune activité ne sert le POSS.');

        // Et la page publique existe — sans redéploiement, sans migration, sans ligne de code.
        $client->request('GET', '/metiers/bowling');
        self::assertResponseIsSuccessful('Le métier créé par l’écran doit avoir sa page tout de suite.');
        self::assertStringContainsString(
            'Logiciel de gestion pour bowling',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * ⚠ **UNE ACTIVITÉ QUI N'EXISTE PAS EST REFUSÉE, ET LE REFUS NOMME LES NEUF.**
     *
     * « Valeur invalide » obligerait celui qui reçoit le refus à aller lire le code pour connaître la
     * liste — c'est-à-dire à avoir accès au dépôt. Le vocabulaire est fermé et public ; l'énumérer
     * coûte une ligne et transforme un refus opaque en une correction évidente.
     */
    public function testUneActiviteHorsDesNeufEstRefuseeEnLesNommant(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/trades');

        $client = static::createClient();
        $refus = $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + [
            'json' => [
                'code' => 'bowling',
                'name' => 'Bowlings',
                'lead' => 'Parties, pistes, ligues.',
                'activities' => ['resource_booking', 'lancer_de_boule'],
            ],
        ]);

        self::assertSame(422, $refus->getStatusCode(), (string) $refus->getContent(false));

        $message = (string) $refus->getContent(false);

        self::assertStringContainsString('lancer_de_boule', $message, 'le refus doit citer ce qui a été reçu');

        // ⚠ Les neuf, toutes : un message qui n'en nommerait que quelques-unes serait pire que rien.
        foreach (['entry', 'resource_booking', 'membership', 'equipment_rental', 'product_sale',
            'coaching', 'appointment', 'lodging', 'dining'] as $activite) {
            self::assertStringContainsString($activite, $message, sprintf('le refus doit nommer « %s »', $activite));
        }

        // Et rien n'a été écrit : un refus qui laisserait une ligne à moitié posée serait pire que
        // l'acceptation, puisqu'il annonce le contraire de ce qu'il a fait.
        self::assertCount(0, $this->em()->getRepository(Trade::class)->findAll());
    }

    /** Un exploitant qui n'est pas l'éditeur ne voit pas que cet écran existe. */
    public function testUnAutreEtablissementNeVoitPasLeReferentiel(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/trades');

        $client = static::createClient();
        $refus = $client->request('GET', '/api/editor/website/trades', [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);

        self::assertSame(404, $refus->getStatusCode(), 'un 403 confirmerait à un curieux que l’écran existe');
    }

    /**
     * ⚠ **UNE ACTIVITÉ DÉCOCHÉE PART VRAIMENT**, et c'est l'inverse exact de la commande de
     * peuplement — les deux ont raison : le déploiement ne doit rien changer à ce qu'un humain a
     * composé, l'humain doit pouvoir décocher.
     *
     * Une méthode qui ne ferait qu'ajouter rendrait le décochage impossible depuis l'écran : la case
     * se décocherait, la requête partirait, la réponse serait un 200, et l'activité serait toujours
     * là au rechargement.
     */
    public function testUneActiviteDecocheeDisparaitEtLesModulesSuivent(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/trades');

        $client = static::createClient();
        $cree = $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + [
            'json' => [
                'code' => 'bowling',
                'name' => 'Bowlings',
                'lead' => 'Parties, pistes, ligues.',
                'status' => 'published',
                'activities' => ['resource_booking', 'equipment_rental'],
            ],
        ])->toArray();

        self::assertContains('Casiers', $cree['modules'], 'témoin de départ : la location allume les casiers.');

        $modifie = $client->request('PATCH', '/api/editor/website/trades/'.$cree['id'], $this->commeEditeur($client, self::FUSION) + [
            'json' => ['activities' => ['resource_booking']],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['resource_booking'], $modifie['activities']);
        self::assertNotContains('Casiers', $modifie['modules'], 'les modules suivent les activités, sans quoi l’écran mentirait.');
        self::assertContains('Réservation de créneaux', $modifie['modules']);
    }

    /**
     * ⚠ **L'ADRESSE D'UN MÉTIER PUBLIÉ NE CHANGE PLUS.**
     *
     * Même règle que pour un article : les liens déjà partagés et les pages déjà indexées
     * pointeraient dans le vide, et rien ici ne le signalerait. Un 404 sur une URL indexée ne se voit
     * pas depuis l'administration ; il se voit chez les moteurs, des semaines plus tard.
     */
    public function testLadresseDunMetierPublieEstGelee(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/trades');

        $client = static::createClient();
        $cree = $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + [
            'json' => [
                'code' => 'bowling',
                'name' => 'Bowlings',
                'lead' => 'Parties.',
                'status' => 'published',
            ],
        ])->toArray();

        $refus = $client->request('PATCH', '/api/editor/website/trades/'.$cree['id'], $this->commeEditeur($client, self::FUSION) + [
            'json' => ['slug' => 'bowling-2026'],
        ]);

        self::assertSame(409, $refus->getStatusCode(), (string) $refus->getContent(false));
        self::assertSame('bowling', $this->ligne('bowling')->getSlug());
    }

    /**
     * ⚠ **LES CINQ MÉTIERS QUE LE PRODUIT PORTE NE SE SUPPRIMENT PAS.**
     *
     * Leur préréglage vit dans le code : un établissement de ce métier continuerait de s'ouvrir avec
     * ses modules. Supprimer la ligne ne retirerait pas le métier du produit, seulement sa PAGE — et
     * son adresse, indexée depuis des mois, rendrait 404.
     *
     * Le refus n'est pas une impasse : le brouillon reste ouvert, et il est réversible.
     */
    public function testUnMetierDuProduitNeSeSupprimePasMaisSeDepublie(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/trades');

        $client = static::createClient();

        /*
         * ⚠ **DEUX MÉTIERS, ET C'EST NÉCESSAIRE.** Le repli est tout-ou-rien : avec une seule ligne
         *   publiée, la dépublier ramènerait ZÉRO ligne, donc les cinq CONSTANTES, donc
         *   `/metiers/piscine` répondrait 200 depuis le repli. Le test aurait échoué en accusant la
         *   dépublication, alors que le site aurait fait exactement ce qu'il annonce.
         *
         *   Ça ne se produit qu'à un état que la production ne connaît pas — `website:trades:seed`
         *   pose les cinq — mais c'est bien le comportement, et il valait d'être écrit ici.
         */
        $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + [
            'json' => ['code' => 'bowling', 'name' => 'Bowlings', 'lead' => 'Parties.', 'status' => 'published'],
        ]);
        self::assertResponseIsSuccessful();

        $piscine = $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + [
            'json' => [
                'code' => 'piscine',
                'name' => 'Piscines',
                'lead' => 'Bassins.',
                'status' => 'published',
                'activities' => ['entry'],
            ],
        ])->toArray();

        self::assertTrue($piscine['builtIn'], 'témoin : « piscine » est bien un métier que le produit porte.');

        $refus = $client->request('DELETE', '/api/editor/website/trades/'.$piscine['id'], $this->commeEditeur($client));
        self::assertSame(409, $refus->getStatusCode(), (string) $refus->getContent(false));
        self::assertCount(2, $this->em()->getRepository(Trade::class)->findAll(), 'la ligne est toujours là.');

        // Et la porte de sortie fonctionne : le brouillon retire la page sans détruire la ligne.
        $client->request('PATCH', '/api/editor/website/trades/'.$piscine['id'], $this->commeEditeur($client, self::FUSION) + [
            'json' => ['status' => 'draft'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/metiers/piscine');
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'un brouillon n’apparaît nulle part sur le site.');
    }

    /**
     * Un métier créé par l'écran peut recevoir son texte de page dans la foulée.
     *
     * ⚠ C'est la vérification qui relie les deux écrans : le métier se crée ici, son corps s'écrit
     * dans `/editor/website/blocks`, et la clé `metier.<code>.body` doit y être DÉCLARÉE. Sans
     * l'élargissement de `SiteBlocks`, ce bloc n'existerait pas et l'écran renverrait 404.
     */
    public function testSonTexteDePageEstAussitotRedigeable(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/trades');

        $client = static::createClient();
        $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + [
            'json' => ['code' => 'bowling', 'name' => 'Bowlings', 'lead' => 'Parties.', 'status' => 'published'],
        ]);
        self::assertResponseIsSuccessful();

        $bloc = $client->request('PUT', '/api/editor/website/blocks/metier.bowling.body', $this->commeEditeur($client) + [
            'json' => ['id' => 'metier.bowling.body', 'value' => ['html' => '<p>Le texte du bowling.</p>']],
        ]);

        self::assertSame(200, $bloc->getStatusCode(), (string) $bloc->getContent(false));

        $client->request('GET', '/metiers/bowling');
        self::assertStringContainsString('Le texte du bowling.', (string) $client->getResponse()->getContent());
    }

    /** Deux métiers ne peuvent pas partager un code : ils partageraient leur texte de page. */
    public function testUnCodeDejaPrisEstRefuse(): void
    {
        $this->sauterSiRouteAbsente('/editor/website/trades');

        $client = static::createClient();
        $corps = ['code' => 'bowling', 'name' => 'Bowlings', 'lead' => 'Parties.'];

        $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + ['json' => $corps]);
        self::assertResponseIsSuccessful();

        $refus = $client->request('POST', '/api/editor/website/trades', $this->commeEditeur($client) + ['json' => $corps]);

        self::assertSame(409, $refus->getStatusCode(), (string) $refus->getContent(false));
        self::assertCount(1, $this->em()->getRepository(Trade::class)->findAll());
    }

    // ---------------------------------------------------------------- montage

    private function ligne(string $slug): Trade
    {
        $ligne = $this->em()->getRepository(Trade::class)->findOneBy(['slug' => $slug]);

        self::assertInstanceOf(Trade::class, $ligne);

        return $ligne;
    }

    /**
     * @param array<string, string> $entetes en-têtes supplémentaires, fusionnés à ceux de l'éditeur
     *
     * @return array<string, mixed>
     */
    private function commeEditeur(object $client, array $entetes = []): array
    {
        /** @var \ApiPlatform\Symfony\Bundle\Test\Client $client */
        return [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)] + $entetes,
        ];
    }

    private function sauterSiRouteAbsente(string $suffixe): void
    {
        static::createClient();

        /** @var \Symfony\Component\Routing\RouterInterface $routeur */
        $routeur = static::getContainer()->get('router');

        foreach ($routeur->getRouteCollection() as $route) {
            if (str_ends_with($route->getPath(), $suffixe)) {
                return;
            }
        }

        self::markTestSkipped('`src/Website/ApiResource` absent de `mapping.paths` (C9).');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
