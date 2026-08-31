<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\DataFixtures\SocleFixtures;
use App\Subscription\Entity\SupportAccess;
use App\Subscription\State\GrantSupportAccessProcessor;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-4 par l'API — ouvrir, lister, refermer un accès d'assistance.
 *
 * **Ce que ce lot répare.** `SupportAccessGuard` existait et **rien ne permettait d'ouvrir un accès**.
 * Le cloisonnement ordinaire refusant déjà à un agent de l'éditeur l'établissement d'un client, la
 * règle RG-ED-07 tenait — mais sans chemin praticable. Or le jour où un client appelle, quelqu'un
 * devra regarder ses données : sans ce chemin, la seule façon est une **affectation permanente**,
 * invisible et que personne ne retirera. Une règle sans chemin praticable se contourne, et le
 * contournement devient la pratique.
 */
final class SupportAccessApiTest extends SocleApiTestCase
{
    public function testOuvrirUnAccesLeFaitApparaitreDansLaListe(): void
    {
        [$client, $entete] = $this->editeur();

        $ouvert = $client->request('POST', '/api/editor/support-accesses', $entete + [
            'json' => [
                'establishmentId' => $this->idEtablissementClient(),
                'reason' => 'Caisse bloquée à l\'ouverture, ticket 4412.',
                'hours' => 2,
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('Caisse bloquée à l\'ouverture, ticket 4412.', $ouvert['reason']);
        self::assertGreaterThan(0, $ouvert['remainingMinutes']);
        self::assertNotSame('', $ouvert['granteeEmail'], 'un acces est nominatif');

        $liste = $client->request('GET', '/api/editor/support-accesses', $entete)->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'] ?? [];
        self::assertCount(1, $membres);
        self::assertSame($ouvert['id'], $membres[0]['id']);
    }

    /**
     * **Le motif est obligatoire, et c'est ce qui distingue une trace d'un formulaire.**
     *
     * Un accès d'assistance sans motif ne se justifie pas devant le client qui le demande.
     */
    public function testUnAccesSansMotifEstRefuse(): void
    {
        [$client, $entete] = $this->editeur();

        $client->request('POST', '/api/editor/support-accesses', $entete + [
            'json' => ['establishmentId' => $this->idEtablissementClient(), 'reason' => '   '],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em()->getRepository(SupportAccess::class)->findAll());
    }

    /**
     * **La durée est bornée courte, pas seulement bornée.**
     *
     * `expiresAt` non nul garantit qu'un accès a un terme ; cette borne garantit qu'il est *court*. Un
     * accès de six mois respecte le schéma et vide la règle de son sens.
     */
    public function testUneFenetreTropLongueEstRefusee(): void
    {
        [$client, $entete] = $this->editeur();

        $reponse = $client->request('POST', '/api/editor/support-accesses', $entete + [
            'json' => [
                'establishmentId' => $this->idEtablissementClient(),
                'reason' => 'Migration de données.',
                'hours' => GrantSupportAccessProcessor::MAX_HOURS + 1,
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertCount(0, $this->em()->getRepository(SupportAccess::class)->findAll());
    }

    /** **Révoquer est un geste** : la liste se vide, et l'entrée reste en base comme historique. */
    public function testRevoquerRetireDeLaListeSansEffacerLHistorique(): void
    {
        [$client, $entete] = $this->editeur();

        $ouvert = $client->request('POST', '/api/editor/support-accesses', $entete + [
            'json' => [
                'establishmentId' => $this->idEtablissementClient(),
                'reason' => 'Vérification d\'un paramétrage TVA.',
            ],
        ])->toArray();

        $client->request('POST', '/api/editor/support-accesses/'.$ouvert['id'].'/revoke', $entete);
        self::assertResponseIsSuccessful();

        $liste = $client->request('GET', '/api/editor/support-accesses', $entete)->toArray();
        self::assertCount(0, $liste['member'] ?? $liste['hydra:member'] ?? []);

        $this->em()->clear();
        $access = $this->em()->getRepository(SupportAccess::class)->find($ouvert['id']);
        self::assertNotNull($access, 'l entree reste : c est l historique de qui a pu voir quoi');
        self::assertNotNull($access->getRevokedAt());
    }

    /** Deux agents peuvent fermer le même accès depuis deux écrans : la seconde fois ne lève pas. */
    public function testRevoquerDeuxFoisNeLevePas(): void
    {
        [$client, $entete] = $this->editeur();

        $ouvert = $client->request('POST', '/api/editor/support-accesses', $entete + [
            'json' => ['establishmentId' => $this->idEtablissementClient(), 'reason' => 'Ticket 4412.'],
        ])->toArray();

        $client->request('POST', '/api/editor/support-accesses/'.$ouvert['id'].'/revoke', $entete);
        $client->request('POST', '/api/editor/support-accesses/'.$ouvert['id'].'/revoke', $entete);

        self::assertResponseIsSuccessful();
    }

    /**
     * **On n'ouvre pas un accès d'assistance sur l'éditeur lui-même.**
     *
     * Ses agents y accèdent par le cloisonnement ordinaire. Un accès d'exception sur son propre tenant
     * masquerait un vrai défaut de droits derrière un mécanisme prévu pour autre chose.
     */
    public function testUnAccesSurLEditeurLuiMemeEstRefuse(): void
    {
        [$client, $entete, $idEditeur] = $this->editeur();

        $reponse = $client->request('POST', '/api/editor/support-accesses', $entete + [
            'json' => ['establishmentId' => $idEditeur, 'reason' => 'Test.'],
        ]);

        self::assertGreaterThanOrEqual(400, $reponse->getStatusCode());
        self::assertCount(0, $this->em()->getRepository(SupportAccess::class)->findAll());
    }

    /**
     * **Un compte client ne voit pas cet écran, et n'apprend pas qu'il existe.**
     *
     * 404 et non 403 : un 403 confirmerait à un client curieux que cet écran existe et qu'il concerne
     * l'éditeur.
     */
    public function testUnCompteClientNeVoitPasLesAcces(): void
    {
        [$client, $enteteClient] = $this->clientOrdinaire();

        $reponse = $client->request('GET', '/api/editor/support-accesses', $enteteClient);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /**
     * ⚠ **OUVRIR un accès est l'opération qui franchit le cloisonnement. Rien ne la prouvait.**
     *
     * Seul le `GET` était couvert. Le `POST` — celui qui distribue réellement le passe-droit — et la
     * révocation ne l'étaient par rien. Trois opérations, une seule preuve.
     *
     * **Le test dit par quel chemin le 404 arrive.** Un 404 peut venir du routage, du cloisonnement,
     * ou d'`assertEditor`. Un test qui se contente de l'attendre resterait vert si la ressource se
     * fermait pour une tout autre raison — y compris parce qu'elle aurait cessé d'exister. On envoie
     * donc **la même charge utile** dans les deux rôles : refusée au client, acceptée à l'éditeur.
     * C'est l'égalité des charges qui fait dire au test « seul le QUI change ».
     *
     * La garde déclarative de cette ressource n'exige qu'être connecté ; le verrou réel est
     * `assertEditor('editor.support_access')` à l'intérieur du provider et des deux processeurs, et
     * `bin/garde-fou-routes-editeur.php` exige qu'il y soit. Ce test mesure l'effet, pas la forme.
     */
    public function testUnCompteClientNePeutPasOuvrirUnAcces(): void
    {
        $charge = [
            'establishmentId' => $this->idEtablissementClient(),
            'reason' => 'Tentative depuis un compte client, ticket inexistant.',
            'hours' => 2,
        ];

        [$clientOrdinaire, $enteteClient] = $this->clientOrdinaire();
        $refuse = $clientOrdinaire->request('POST', '/api/editor/support-accesses', $enteteClient + [
            'json' => $charge,
        ]);
        self::assertSame(404, $refuse->getStatusCode(), (string) $refuse->getContent(false));

        // Témoin : la MÊME charge passe pour l'éditeur. Sans lui, le 404 ci-dessus pourrait venir
        // d'une charge invalide, d'une route disparue, ou de n'importe quoi d'autre.
        [$editeur, $enteteEditeur] = $this->editeur();
        $editeur->request('POST', '/api/editor/support-accesses', $enteteEditeur + ['json' => $charge]);
        self::assertResponseIsSuccessful('La charge est valide : seul le rôle de l’appelant change.');
    }

    /**
     * La révocation aussi : c'est une opération d'écriture sur le même mécanisme.
     *
     * ⚠ On révoque un accès **qui existe**, ouvert par l'éditeur juste avant. Révoquer un
     * identifiant inventé rendrait 404 pour une raison qui n'a rien à voir — et le test serait vert
     * sans rien mesurer.
     */
    public function testUnCompteClientNePeutPasRevoquerUnAcces(): void
    {
        [$editeur, $enteteEditeur] = $this->editeur();
        $ouvert = $editeur->request('POST', '/api/editor/support-accesses', $enteteEditeur + [
            'json' => [
                'establishmentId' => $this->idEtablissementClient(),
                'reason' => 'Ouvert par l’éditeur pour éprouver la révocation.',
                'hours' => 2,
            ],
        ])->toArray();

        [$clientOrdinaire, $enteteClient] = $this->clientOrdinaire();
        $refuse = $clientOrdinaire->request(
            'POST',
            '/api/editor/support-accesses/' . $ouvert['id'] . '/revoke',
            $enteteClient
        );
        self::assertSame(404, $refuse->getStatusCode(), (string) $refuse->getContent(false));

        // Témoin : l'accès est toujours ouvert — le refus n'a rien révoqué au passage.
        $liste = $editeur->request('GET', '/api/editor/support-accesses', $enteteEditeur)->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'] ?? [];
        self::assertCount(1, $membres, 'Le refus ne doit pas avoir révoqué.');
        self::assertSame($ouvert['id'], $membres[0]['id']);
    }

    // ---------------------------------------------------------------- montage

    protected function setUp(): void
    {
        parent::setUp();

        // L'éditeur est l'établissement A ; B tient lieu de client à assister.
        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>, 2: string} */
    private function editeur(): array
    {
        $client = static::createClient();
        $entete = [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];

        return [$client, $entete, $this->idEtablissement(SocleFixtures::ETAB_A_NOM)];
    }

    /**
     * Le même administrateur, mais l'établissement actif est celui d'un client.
     *
     * C'est le montage fidèle : ce qui doit refuser, ce n'est pas le compte, c'est la session. Monté
     * sur un compte sans droits, le test aurait pu passer au vert en mesurant un défaut d'autorisation
     * au lieu du contrôle de tenant.
     *
     * @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>}
     */
    private function clientOrdinaire(): array
    {
        $client = static::createClient();
        $entete = [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ];

        return [$client, $entete];
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function idEtablissementClient(): string
    {
        return $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
    }
}
