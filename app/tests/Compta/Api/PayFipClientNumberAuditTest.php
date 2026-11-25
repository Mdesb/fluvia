<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Audit\Entity\EntreeAudit;
use App\Compta\Entity\ProfilExploitant;
use App\DataFixtures\SocleFixtures;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G-5 : la saisie, le remplacement et l'effacement du numéro client PayFiP laissent chacun UNE entrée d'audit
 * (qui, quand, quel profil, quel établissement) avec l'ancienne et la nouvelle valeur EN CLAIR : le numéro n'est
 * pas un secret (contrairement à la clé Stripe, il n'est pas dans `CHAMPS_SENSIBLES`).
 */
final class PayFipClientNumberAuditTest extends ComptaApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @return list<EntreeAudit> */
    private function entries(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(EntreeAudit::class)->findBy(['cibleType' => ProfilExploitant::class], ['dateHeure' => 'ASC', 'id' => 'ASC']);
    }

    /** @param array<string, mixed> $entete @param array<string, mixed> $json */
    private function patchNumber(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $id, array $json): void
    {
        $client->request('PATCH', '/api/profil_exploitants/' . $id . '/payfip_client_number', [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => $json,
        ] + $entete);
        self::assertResponseIsSuccessful();
    }

    /** @return list<string> identifiants des entrées déjà présentes */
    private function knownIds(): array
    {
        return array_map(static fn (EntreeAudit $e): string => (string) $e->getId(), $this->entries());
    }

    /**
     * Les entrées apparues depuis `$known`, par IDENTIFIANT : le tri (date à la seconde, puis uuid aléatoire) ne
     * garantit pas l'ordre d'insertion, une tranche par rang tombait au hasard.
     *
     * @param list<string> $known
     *
     * @return list<EntreeAudit>
     */
    private function newEntriesSince(array $known): array
    {
        return array_values(array_filter($this->entries(), static fn (EntreeAudit $e): bool => !\in_array((string) $e->getId(), $known, true)));
    }

    public function testEnterReplaceAndClearAreEachAuditedOnceWithBothValues(): void
    {
        $id = (string) $this->profilExploitant()->getId();
        [$client, $entete, $idA] = $this->adminSurA();

        // Saisie : null -> 123456.
        $before = $this->knownIds();
        $this->patchNumber($client, $entete, $id, ['payfipClientNumber' => '123456']);
        $new = $this->newEntriesSince($before);
        self::assertCount(1, $new);
        $entry = $new[0];
        self::assertSame('modification', $entry->getAction());
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $entry->getAuteur());
        self::assertSame($id, $entry->getCibleId());
        self::assertSame($idA, (string) $entry->getEtablissement());
        self::assertArrayHasKey('payfipClientNumber', $entry->getValeurAvant() ?? []);
        self::assertNull($entry->getValeurAvant()['payfipClientNumber']);
        self::assertSame('123456', $entry->getValeurApres()['payfipClientNumber'] ?? null);

        // Remplacement : 123456 -> 654321.
        $before = $this->knownIds();
        $this->patchNumber($client, $entete, $id, ['payfipClientNumber' => '654321']);
        $new = $this->newEntriesSince($before);
        self::assertCount(1, $new);
        self::assertSame('123456', $new[0]->getValeurAvant()['payfipClientNumber'] ?? null);
        self::assertSame('654321', $new[0]->getValeurApres()['payfipClientNumber'] ?? null);

        // Effacement : 654321 -> null.
        $before = $this->knownIds();
        $this->patchNumber($client, $entete, $id, ['payfipClientNumber' => null]);
        $new = $this->newEntriesSince($before);
        self::assertCount(1, $new);
        self::assertSame('654321', $new[0]->getValeurAvant()['payfipClientNumber'] ?? null);
        self::assertArrayHasKey('payfipClientNumber', $new[0]->getValeurApres() ?? []);
        self::assertNull($new[0]->getValeurApres()['payfipClientNumber']);
    }

    public function testAnUnchangedPatchWritesNoEntry(): void
    {
        $id = (string) $this->profilExploitant()->getId();
        [$client, $entete] = $this->adminSurA();
        $this->patchNumber($client, $entete, $id, ['payfipClientNumber' => '123456']);

        $before = \count($this->entries());
        self::assertGreaterThan(0, $before, 'témoin : la saisie précédente a bien laissé une entrée');
        $this->patchNumber($client, $entete, $id, ['payfipClientNumber' => '123456']);

        self::assertCount($before, $this->entries());
    }
}
