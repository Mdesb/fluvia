<?php

declare(strict_types=1);

namespace App\Tests\Audit\Api;

use App\Audit\Service\ClientAuditRedactor;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #363 (G-6) — la lecture du journal ne change pas : les entrées d'une fiche anonymisée sortent
 * telles qu'elles sont en base, avec le marqueur, par l'API comme par l'export.
 */
final class ClientAuditRedactionReadTest extends CrmApiTestCase
{
    public function testApiReturnsRedactedEntriesWithMarkerAndExportListsThemWithoutIdentity(): void
    {
        [$client, $headers] = $this->adminSurA();
        $em = $this->em();
        $establishment = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $customer = (new Client())
            ->setGroupe($establishment->getRegion()?->getGroupe())
            ->setEtablissementCreation($establishment)
            ->setNom('Dupont')
            ->setPrenom('Alice')
            ->setEmail('dupont@example.test')
            ->setDateCreation(new \DateTimeImmutable('-5 years'));
        $em->persist($customer);
        $em->flush();
        $customer->setTelephone('0611223344');
        $em->flush();
        $id = (string) $customer->getId();

        // TÉMOIN POSITIF : avant l'anonymisation, l'API rend bien l'identité et le filtre `cibleType` n'est pas muet.
        $before = $this->entriesOfCustomer($client, $headers, $id);
        self::assertCount(2, $before);
        self::assertStringContainsString('Dupont', (string) json_encode($before));
        $all = $client->request('GET', '/api/entree_audits', $headers)->toArray();
        self::assertGreaterThan(\count($before), \count($all['member'] ?? $all['hydra:member']), 'sans filtre, la collection est plus large : le filtre cibleType filtre bien');

        $client->request('POST', '/api/rgpd/purge', $headers + ['json' => ['inactiveMonths' => 36, 'clientIds' => [$id], 'confirm' => true]]);
        self::assertResponseIsSuccessful();

        $after = $this->entriesOfCustomer($client, $headers, $id);
        $text = (string) json_encode($after, JSON_UNESCAPED_UNICODE);
        foreach (['Dupont', 'Alice', 'dupont@example.test', '0611223344'] as $value) {
            self::assertStringNotContainsString($value, $text);
        }
        self::assertStringContainsString(ClientAuditRedactor::MARKER, $text);
        self::assertGreaterThanOrEqual(2, \count($after), 'les entrées antérieures sont toujours lues, caviardées');

        // L'export CSV ne porte aucune valeur (colonnes id, date, auteur, action, cible, établissement) :
        // il liste les mêmes entrées et ne peut donc pas rendre l'ancienne identité.
        $export = $client->request('GET', '/audit/export', $headers + ['query' => ['cibleType' => Client::class]]);
        self::assertResponseIsSuccessful();
        $csv = $export->getContent();
        self::assertSame(\count($after), substr_count($csv, ';' . $id . ';'), 'l\'export liste les entrées de la fiche');
        foreach (['Dupont', 'Alice', 'dupont@example.test', '0611223344'] as $value) {
            self::assertStringNotContainsString($value, $csv);
        }
    }

    /** @param array<string, mixed> $headers @return list<array<string, mixed>> */
    private function entriesOfCustomer(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $headers, string $id): array
    {
        $response = $client->request('GET', '/api/entree_audits', $headers + ['query' => ['cibleType' => Client::class, 'itemsPerPage' => 100]])->toArray();
        $members = $response['member'] ?? $response['hydra:member'];

        return array_values(array_filter($members, static fn (array $m): bool => ($m['cibleId'] ?? null) === $id));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
