<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Audit\Entity\EntreeAudit;
use App\Audit\Service\ClientAuditRedactor;
use App\Audit\Service\JournalAudit;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Entity\RegleConservation;
use App\Crm\Enum\PrivacyRequestOrigin;
use App\Crm\Enum\StatutClient;
use App\Crm\Service\EffacementRgpdHandler;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #363 — l'anonymisation caviarde l'audit de la fiche, par les trois voies, dans la même transaction.
 */
final class RgpdAuditRedactionTest extends CrmApiTestCase
{
    private const U1_VALUES = ['Dupont', 'Alice', 'dupont@example.test', '0611223344'];

    public function testRequestedAnonymisationRedactsTheCustomerAudit(): void
    {
        [$client, $headers] = $this->adminSurA();
        $ids = $this->seed();
        $this->assertIdentityPresent($ids['u1']);
        $frozenRows = $this->rowsOutsideCustomer($ids['u1']);

        $request = $client->request('POST', '/api/demande_rgpds', $headers + ['json' => ['client' => '/api/clients/' . $ids['u1'], 'type' => 'anonymisation']])->toArray();
        $client->request('POST', '/api/demandes-rgpd/' . $request['id'] . '/traiter', $headers);
        self::assertResponseIsSuccessful();

        $this->assertRedacted($ids, $frozenRows);
    }

    public function testPurgeRedactsTheCustomerAudit(): void
    {
        [$client, $headers] = $this->adminSurA();
        $ids = $this->seed();
        $this->assertIdentityPresent($ids['u1']);
        $frozenRows = $this->rowsOutsideCustomer($ids['u1']);

        $client->request('POST', '/api/rgpd/purge', $headers + ['json' => ['inactiveMonths' => 36, 'clientIds' => [$ids['u1']], 'confirm' => true]]);
        self::assertResponseIsSuccessful();

        $this->assertRedacted($ids, $frozenRows);
    }

    public function testRetentionRedactsTheCustomerAudit(): void
    {
        $this->adminSurA();
        $ids = $this->seed();
        $em = $this->em();
        $rule = (new RegleConservation())->setGroupe($em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM])->getRegion()?->getGroupe())->setCategorieDonnee('identite')->setDureeMois(36);
        $em->persist($rule);
        $em->flush();
        $this->assertIdentityPresent($ids['u1']);
        $frozenRows = $this->rowsOutsideCustomer($ids['u1']);

        $tester = new CommandTester((new Application(static::$kernel))->find('crm:rgpd:appliquer-conservation'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertRedacted($ids, $frozenRows);
        $trace = $this->connection()->fetchAssociative('SELECT auteur FROM audit_entree WHERE action = ? AND cible_id = ?', ['crm.rgpd.caviardage', $ids['u1']]);
        self::assertSame('tache:crm:rgpd:appliquer-conservation', $trace['auteur'], 'la tâche planifiée signe la trace');
    }

    /**
     * UNE SEULE TRANSACTION. Un écouteur casse la SECONDE écriture d'un `Client` ou d'une `EntreeAudit` :
     * la première est donc déjà partie. Rien ne doit rester : ni fiche anonymisée, ni audit caviardé.
     */
    public function testFailureWhileWritingRollsBackAnonymisationAndRedaction(): void
    {
        $this->adminSurA();
        $ids = $this->seed();
        $em = $this->em();
        $this->assertIdentityPresent($ids['u1']);
        $before = $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree ORDER BY id');

        $listener = new class {
            public int $seen = 0;

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                $object = $args->getObject();
                if ($object instanceof Client || $object instanceof EntreeAudit) {
                    if (++$this->seen === 2) {
                        throw new \RuntimeException('panne injectée');
                    }
                }
            }
        };
        $em->getEventManager()->addEventListener(Events::postUpdate, $listener);

        $handler = new EffacementRgpdHandler($em, new ClientAuditRedactor($em, static::getContainer()->get(JournalAudit::class)));
        $customer = $em->getRepository(Client::class)->find($ids['u1']);
        $handler->anonymiseWithoutRequest($customer, PrivacyRequestOrigin::Purge, null);
        try {
            $em->flush();
            self::fail('le flush devait échouer');
        } catch (\RuntimeException $e) {
            self::assertSame('panne injectée', $e->getMessage());
        }
        self::assertSame(2, $listener->seen, 'témoin : une première écriture est bien partie avant la panne');

        // Lecture sur une connexion neuve : ce que la base a vraiment gardé.
        $freshConnection = DriverManager::getConnection($em->getConnection()->getParams());
        self::assertSame($before, $freshConnection->fetchAllAssociative('SELECT * FROM audit_entree ORDER BY id'), 'l\'audit doit être inchangé');
        self::assertSame('actif', $freshConnection->fetchOne('SELECT statut FROM crm_client WHERE id = ?', [$em->getConnection()->fetchOne('SELECT id FROM crm_client WHERE nom = ?', ['Dupont'])]));
        self::assertSame('Dupont', $freshConnection->fetchOne('SELECT nom FROM crm_client WHERE nom = ?', ['Dupont']));
        self::assertSame(0, (int) $freshConnection->fetchOne('SELECT COUNT(*) FROM audit_entree WHERE action = ?', ['crm.rgpd.caviardage']));
        $freshConnection->close();
    }

    /**
     * @param array{u1: string, u2: string} $ids
     * @param array<string, array<string, mixed>> $frozenRows les lignes hors fiche avant l'anonymisation
     */
    private function assertRedacted(array $ids, array $frozenRows): void
    {
        $this->em()->clear();
        self::assertSame(StatutClient::Anonymise, $this->em()->getRepository(Client::class)->find($ids['u1'])->getStatut());

        $after = $this->auditText($ids['u1']);
        foreach (self::U1_VALUES as $value) {
            self::assertStringNotContainsString($value, $after, "« $value » reste dans l'audit de la fiche anonymisée");
        }
        self::assertStringContainsString(ClientAuditRedactor::MARKER, $after);

        // Une trace, qui compte les 2 entrées antérieures (pas l'entrée d'anonymisation, écrite au flush).
        $traces = $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE action = ? AND cible_id = ?', ['crm.rgpd.caviardage', $ids['u1']]);
        self::assertCount(1, $traces);
        self::assertSame(['entrees' => 2], json_decode((string) $traces[0]['valeur_apres'], true));

        // L'entrée d'anonymisation : l'identité en est absente (pas masquée), et elle n'est pas caviardée.
        $anonymisationRows = $this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE cible_type = ? AND cible_id = ? AND action = ? ORDER BY date_heure DESC, id', [Client::class, $ids['u1'], 'modification']);
        $current = array_filter($anonymisationRows, static fn (array $l): bool => str_contains((string) $l['valeur_apres'], 'anonymise'));
        self::assertCount(1, $current, 'une seule entrée d\'anonymisation');
        $entry = json_decode((string) array_values($current)[0]['valeur_apres'], true);
        self::assertArrayNotHasKey('nom', $entry);
        self::assertNotContains(ClientAuditRedactor::MARKER, $entry);

        // U2, la famille, l'entrée aux clés d'identité d'une autre classe : identiques ligne à ligne.
        $rows = $this->rowsOutsideCustomer($ids['u1']);
        foreach ($frozenRows as $id => $row) {
            self::assertSame($row, $rows[$id], 'une entrée hors de la fiche a changé');
        }
        self::assertStringContainsString('Martin', $this->auditText($ids['u2']));
        self::assertStringNotContainsString(ClientAuditRedactor::MARKER, $this->auditText($ids['u2']));
    }

    private function assertIdentityPresent(string $u1): void
    {
        $text = $this->auditText($u1);
        foreach (self::U1_VALUES as $value) {
            self::assertStringContainsString($value, $text, "témoin : « $value » doit être dans l'audit avant anonymisation");
        }
    }

    /** @return array{u1: string, u2: string} */
    private function seed(): array
    {
        $em = $this->em();
        $establishment = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $u1 = $this->createCustomer($establishment, 'Dupont', 'Alice', '0611223344', null);
        $u2 = $this->createCustomer($establishment, 'Martin', 'Bruno', '0699887766', new \DateTimeImmutable('-1 month'));

        $family = (new Famille())->setLibelle('Famille Dupont')->setGroupe($u1->getGroupe())->setPayeurPrincipal($u2);
        $em->persist($family);
        $em->flush();
        $foreign = static::getContainer()->get(JournalAudit::class)->enregistrer('operation', 'App\Vente\Nf525\Entity\OperationScellee', (string) $u1->getId(), null, 'tache:test');
        $foreign->setValeurApres(['nom' => 'Dupont', 'email' => 'dupont@example.test', 'montant' => '12.00']);
        $em->flush();

        return ['u1' => (string) $u1->getId(), 'u2' => (string) $u2->getId()];
    }

    private function createCustomer(Etablissement $establishment, string $name, string $firstName, string $phone, ?\DateTimeImmutable $lastVisit): Client
    {
        $em = $this->em();
        $c = (new Client())
            ->setGroupe($establishment->getRegion()?->getGroupe())
            ->setEtablissementCreation($establishment)
            ->setNom($name)
            ->setPrenom($firstName)
            ->setEmail(strtolower($name) . '@example.test')
            ->setTelephone($phone)
            ->setDateCreation(new \DateTimeImmutable('-5 years'))
            ->setDateDerniereVisite($lastVisit);
        $em->persist($c);
        $em->flush(); // « creation »
        $c->setEmail(strtolower($name) . '+2@example.test');
        $em->flush(); // « modification »

        return $c;
    }

    /** Le texte des valeurs de la fiche, JSON relu (le stockage échappe l'Unicode). */
    private function auditText(string $clientId): string
    {
        $out = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT valeur_avant, valeur_apres FROM audit_entree WHERE cible_type = ? AND cible_id = ?', [Client::class, $clientId]) as $l) {
            $out[] = json_encode([json_decode((string) $l['valeur_avant'], true), json_decode((string) $l['valeur_apres'], true)], JSON_UNESCAPED_UNICODE);
        }

        return implode('|', $out);
    }

    /** @return array<string, array<string, mixed>> */
    private function rowsOutsideCustomer(string $clientId): array
    {
        $out = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT * FROM audit_entree WHERE NOT (cible_type = ? AND cible_id = ?) ORDER BY id', [Client::class, $clientId]) as $l) {
            $out[$l['id']] = $l;
        }

        return $out;
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
