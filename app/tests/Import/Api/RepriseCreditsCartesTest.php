<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Tests\Import\ImportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reprise des crédits de cartes — le seul type qui porte de l'argent (SPEC §4).
 *
 * **Ce qui est en jeu.** Un crédit restant est une dette : le client a payé dix entrées, en a
 * consommé quatre, on lui en doit six. Une erreur ne se voit pas à la reprise ; elle se voit au
 * guichet, six semaines plus tard, devant la personne à qui il manque des entrées. Ces essais
 * portent donc surtout sur ce que la reprise **refuse**.
 */
final class RepriseCreditsCartesTest extends ImportApiTestCase
{
    /** Un CSV de crédits, tel qu'un ancien logiciel l'exporte. */
    private function csvCredits(string ...$lignes): string
    {
        return implode("\n", array_merge(
            ['externalRef;supportIdentifiant;creditRestant;validUntil'],
            $lignes,
        ));
    }

    public function testSansTotalAnnonceLeLotEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/imports', $entete + ['json' => [
            'type' => 'card_credits',
            'content' => $this->csvCredits('C-1;CARTE-001;6;'),
            // Pas de `announcedTotal` : c'est le point du test.
        ]]);
        self::assertResponseIsSuccessful();

        $lot = $client->getResponse()->toArray();
        self::assertSame('rejected', $lot['status']);
        self::assertStringContainsString('announcedTotal', $lot['errors']['0']);
        self::assertStringContainsString('devine pas une dette', $lot['errors']['0']);
    }

    public function testUnEcartDUneSeuleEntreeRefuseLeLot(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/imports', $entete + ['json' => [
            'type' => 'card_credits',
            'content' => $this->csvCredits('C-1;CARTE-001;6;', 'C-2;CARTE-002;4;'),
            'announcedTotal' => 11, // le fichier en totalise 10
        ]]);
        self::assertResponseIsSuccessful();

        $lot = $client->getResponse()->toArray();
        self::assertSame('rejected', $lot['status'], 'Un écart d\'une unité suffit à refuser.');
        self::assertStringContainsString('écart de 1', $lot['errors']['0']);
    }

    public function testUneCarteEpuiseeNEstPasReprise(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/imports', $entete + ['json' => [
            'type' => 'card_credits',
            'content' => $this->csvCredits('C-1;CARTE-001;6;', 'C-2;CARTE-002;0;'),
            'announcedTotal' => 6,
        ]]);
        self::assertResponseIsSuccessful();

        $lot = $client->getResponse()->toArray();
        self::assertSame('rejected', $lot['status']);
        self::assertStringContainsString('épuisée', $lot['errors']['3']);
    }

    public function testUnLotJusteCreeLesCreditsEtLesRendTracables(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $id = $this->deposerCredits($client, $entete, $this->csvCredits(
            'C-10;CARTE-010;6;2027-06-30',
            'C-11;CARTE-011;4;',
        ), 10);

        $client->request('POST', '/api/imports/' . $id . '/apply', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $client->getResponse()->toArray()['createdRows']);

        $droit = $this->droitParReference('C-10');
        self::assertNotNull($droit);
        self::assertSame(6, $droit->getCreditRestant());
        self::assertSame('carte_quota', $droit->getSourceType()->value);

        // ⚠ La traçabilité est l'exigence propre à ce type : une contestation doit remonter au
        // fichier d'origine, pas à la mémoire de qui était au guichet ce jour-là.
        self::assertSame($id, (string) $droit->getImportBatchRef());

        // Le crédit est présentable : la carte physique existe et lui est appairée.
        self::assertNotNull($this->supportParIdentifiant('CARTE-010'));
        self::assertSame(1, $this->nombreAppairages($droit));
    }

    public function testAnnulerRendLesCreditsMaisPasLesCartesPhysiques(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $id = $this->deposerCredits($client, $entete, $this->csvCredits('C-20;CARTE-020;8;'), 8);
        $client->request('POST', '/api/imports/' . $id . '/apply', $entete);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->droitParReference('C-20'));

        $client->request('POST', '/api/imports/' . $id . '/revert', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('reverted', $client->getResponse()->toArray()['status']);

        self::assertNull($this->droitParReference('C-20'), 'Le crédit repris disparaît.');

        // ⚠ La carte physique reste : elle existait avant la reprise et existe après. On défait ce
        // qu'on a écrit, pas ce qu'on a rencontré.
        self::assertNotNull($this->supportParIdentifiant('CARTE-020'), 'Le support n\'est pas supprimé par une annulation.');
    }

    /** @param array<string, mixed> $entete */
    private function deposerCredits(object $client, array $entete, string $contenu, int $total): string
    {
        $client->request('POST', '/api/imports', $entete + ['json' => [
            'type' => 'card_credits',
            'fileName' => 'credits.csv',
            'content' => $contenu,
            'announcedTotal' => $total,
        ]]);
        self::assertResponseIsSuccessful();

        $lot = $client->getResponse()->toArray();
        self::assertSame('validated', $lot['status'], sprintf('Erreurs : %s', json_encode($lot['errors'])));

        return $lot['id'];
    }

    private function droitParReference(string $reference): ?DroitAcces
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(DroitAcces::class)->findOneBy(['externalRef' => $reference]);
    }

    private function supportParIdentifiant(string $identifiant): ?Support
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em->getRepository(Support::class)->findOneBy(['identifiant' => $identifiant]);
    }

    private function nombreAppairages(DroitAcces $droit): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return \count($em->getRepository(Appairage::class)->findBy(['droit' => $droit]));
    }
}
