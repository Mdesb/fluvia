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
