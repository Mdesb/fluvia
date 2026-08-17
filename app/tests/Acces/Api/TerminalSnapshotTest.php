<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Tests\Acces\AccesApiTestCase;

/**
 * Snapshot local incrémental/complet (GET /terminal/snapshot, US-TERM-03/04/05, CA-5/6, §4.3 spec).
 */
final class TerminalSnapshotTest extends AccesApiTestCase
{
    public function testCa5SnapshotCompletPuisDeltaVide(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        $complet = $client->request('GET', '/api/terminal/snapshot', $entete)->toArray();
        self::assertGreaterThan(0, $complet['versionCourante']);
        self::assertNotEmpty($complet['entrees']);
        self::assertContains(AccesFixtures::SUPPORT_IDENTIFIANT, array_column($complet['entrees'], 'identifiant'));

        $delta = $client->request('GET', '/api/terminal/snapshot', $entete + ['query' => ['depuis' => $complet['versionCourante']]])->toArray();
        self::assertSame([], $delta['entrees'], 'Delta correct : pas de sur-transmission sans mutation entre-temps.');
    }

    public function testCa6SupportBloqueApparaitEnTombstoneAuDeltaSuivant(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        $complet = $client->request('GET', '/api/terminal/snapshot', $entete)->toArray();
        $curseur = $complet['versionCourante'];

        [$adminClient, $adminEntete] = $this->adminSurA();
        $blocage = $adminClient->request('POST', '/api/acces/supports/' . $this->idSupport() . '/bloquer', $adminEntete + [
            'json' => ['motif' => 'Test CA-6 tombstone snapshot terminal'],
        ]);
        self::assertContains($blocage->getStatusCode(), [200, 201], (string) $blocage->getContent(false));

        $delta = $client->request('GET', '/api/terminal/snapshot', $entete + ['query' => ['depuis' => $curseur]])->toArray();
        self::assertNotEmpty($delta['entrees']);
        $entree = current(array_filter($delta['entrees'], static fn (array $e): bool => $e['identifiant'] === AccesFixtures::SUPPORT_IDENTIFIANT));
        self::assertNotFalse($entree, 'Le support bloqué doit apparaître au delta (tombstone).');
        self::assertTrue($entree['revoque']);
    }

    public function testPaginationSnapshotCompletJusquaStableEntrePages(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        // Support de démonstration + au moins 2 supports additionnels pour un lot de 3 (etab A).
        $this->creerSupportsSupplementaires(2);

        $page1 = $client->request('GET', '/api/terminal/snapshot', $entete + ['query' => ['taille' => 1, 'page' => 1]])->toArray();
        self::assertCount(1, $page1['entrees']);
        self::assertTrue($page1['pageSuivante']);
        self::assertSame(1, $page1['taillepage']);

        $page2 = $client->request('GET', '/api/terminal/snapshot', $entete + ['query' => ['taille' => 1, 'page' => 2, 'jusqua' => $page1['versionCourante']]])->toArray();
        self::assertCount(1, $page2['entrees']);
        self::assertSame($page1['versionCourante'], $page2['versionCourante'], 'jusqua stable entre pages (pas de dérive pendant la pagination).');
        self::assertNotSame($page1['entrees'][0]['identifiant'], $page2['entrees'][0]['identifiant']);
    }

    private function creerSupportsSupplementaires(int $n): void
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);

        for ($i = 0; $i < $n; ++$i) {
            $support = new \App\Acces\Entity\Support();
            $support->setIdentifiant('SNAP-DEMO-' . $i . '-' . substr((string) \Symfony\Component\Uid\Uuid::v4(), 0, 8))
                ->setType(\App\Acces\Enum\TypeSupport::Qr)
                ->setEtablissement($etab)
                ->setVersionMaj(0);
            $em->persist($support);
        }
        $em->flush();

        // Chaque support additionnel doit porter une version distincte pour être paginé de façon
        // stable : on les fait passer par un blocage/déblocage pour leur attribuer une versionMaj via
        // le séquenceur applicatif (évite d'accéder directement au service interne depuis le test).
        $sequencer = static::getContainer()->get(\App\Acces\Service\VersionSnapshotSequencer::class);
        foreach ($em->getRepository(\App\Acces\Entity\Support::class)->findBy(['etablissement' => $etab]) as $support) {
            $support->setVersionMaj($sequencer->suivant());
        }
        $em->flush();
    }
}
