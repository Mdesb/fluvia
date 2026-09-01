<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Crm\Entity\Client;
use App\Tests\Import\ImportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reprise initiale, type `customers` (SPEC-REPRISE-INITIALE).
 *
 * **Ce que ces essais protègent, dans l'ordre où ça coûte cher.** Un import qui écrit à moitié
 * laisse un état que personne ne sait démêler ; un import qui duplique fabrique deux fiches pour la
 * même personne, découvertes des mois plus tard par une réclamation ; une annulation qui supprime un
 * client déjà employé détruit une écriture au lieu de corriger un fichier.
 */
final class RepriseClientsTest extends ImportApiTestCase
{
    public function testUnFichierBonEstValideSansRienEcrire(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $avant = $this->nombreDeClients();

        $client->request('POST', '/api/imports', $entete + ['json' => [
            'type' => 'customers',
            'fileName' => 'clients.csv',
            'content' => $this->csv(
                'ANC-1;physique;Dupont;Jean;;jean@example.test;;1980-05-04',
                'ANC-2;morale;;;Mairie de Ville;contact@ville.test;;',
            ),
        ]]);
        self::assertResponseIsSuccessful();

        $lot = $client->getResponse()->toArray();
        self::assertSame('validated', $lot['status']);
        self::assertSame(2, $lot['rowCount']);
        self::assertSame([], $lot['errors']);

        // ⚠ Le cœur du premier temps : valider n'écrit RIEN en base métier.
        self::assertSame($avant, $this->nombreDeClients(), 'L\'analyse ne doit créer aucun client.');
    }

    public function testUnFichierMauvaisEstRefuseEnNommantToutesLesLignes(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/imports', $entete + ['json' => [
            'type' => 'customers',
            'content' => $this->csv(
                'ANC-1;physique;Dupont;Jean;;;;',        // bonne
                ';physique;Martin;Paul;;;;',             // référence absente
                'ANC-3;physique;;Anne;;;;',              // nom manquant pour un physique
                'ANC-1;physique;Autre;Jean;;;;',         // référence déjà vue plus haut
            ),
        ]]);
        self::assertResponseIsSuccessful();

        $lot = $client->getResponse()->toArray();
        self::assertSame('rejected', $lot['status']);

        // ⚠ TOUTES les lignes fautives, pas la première. Refuser en nommant une seule ligne
        // condamne l'exploitant à autant d'allers-retours qu'il a de fautes — c'est ce qui fait
        // renoncer à la reprise et ressaisir à la main.
        self::assertCount(3, $lot['errors'], 'Les trois lignes fautives doivent être nommées.');
        self::assertArrayHasKey('3', $lot['errors']);
        self::assertArrayHasKey('4', $lot['errors']);
        self::assertArrayHasKey('5', $lot['errors']);
        self::assertStringContainsString('externalRef', $lot['errors']['3']);
        self::assertStringContainsString('nom est requis', $lot['errors']['4']);
        self::assertStringContainsString('apparaît déjà ligne 2', $lot['errors']['5']);
    }

    public function testAppliquerCreeLesClientsEtLesRattacheAuLot(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $avant = $this->nombreDeClients();
        $id = $this->deposer($client, $entete, $this->csv(
            'ANC-10;physique;Durand;Marie;;marie@example.test;;',
            'ANC-11;morale;;;Club Nautique;club@example.test;;',
        ));

        $client->request('POST', '/api/imports/' . $id . '/apply', $entete);
        self::assertResponseIsSuccessful();

        $lot = $client->getResponse()->toArray();
        self::assertSame('applied', $lot['status']);
        self::assertSame(2, $lot['createdRows']);
        self::assertSame($avant + 2, $this->nombreDeClients());

        $repris = $this->clientParReference('ANC-10');
        self::assertNotNull($repris);
        self::assertSame('Durand', $repris->getNom());
        self::assertSame($id, (string) $repris->getImportBatchRef(), 'Chaque ligne créée porte son lot.');
    }

    public function testRejouerUnFichierCorrigeNeDupliquePas(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $premier = $this->deposer($client, $entete, $this->csv('ANC-20;physique;Petit;Luc;;;;'));
        $client->request('POST', '/api/imports/' . $premier . '/apply', $entete);
        self::assertResponseIsSuccessful();

        $apresPremier = $this->nombreDeClients();

        // Le même client, plus un nouveau : c'est le geste réel d'un exploitant qui corrige et
        // redépose son fichier entier plutôt que de le découper à la main.
        $second = $this->deposer($client, $entete, $this->csv(
            'ANC-20;physique;Petit;Luc;;;;',
            'ANC-21;physique;Grand;Sophie;;;;',
        ));
        $client->request('POST', '/api/imports/' . $second . '/apply', $entete);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $client->getResponse()->toArray()['createdRows'], 'Seule la ligne neuve est créée.');
        self::assertSame($apresPremier + 1, $this->nombreDeClients());
    }

    public function testLeMemeFichierDeposeDeuxFoisEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $contenu = $this->csv('ANC-30;physique;Roux;Ana;;;;');
        $this->deposer($client, $entete, $contenu);

        $client->request('POST', '/api/imports', $entete + ['json' => ['type' => 'customers', 'content' => $contenu]]);
        // Deux lots identiques rendraient l'annulation ambiguë : lequel a créé quoi ?
        self::assertResponseStatusCodeSame(409);
    }

    public function testAnnulerSupprimeExactementCeQueLeLotAvaitCree(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $avant = $this->nombreDeClients();
        $id = $this->deposer($client, $entete, $this->csv(
            'ANC-40;physique;Blanc;Yves;;;;',
            'ANC-41;physique;Noir;Eva;;;;',
        ));
        $client->request('POST', '/api/imports/' . $id . '/apply', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame($avant + 2, $this->nombreDeClients());

        $client->request('POST', '/api/imports/' . $id . '/revert', $entete);
        self::assertResponseIsSuccessful();

        self::assertSame('reverted', $client->getResponse()->toArray()['status']);
        self::assertSame($avant, $this->nombreDeClients(), 'L\'annulation rend la base à son état d\'avant.');
        self::assertNull($this->clientParReference('ANC-40'));
    }

    public function testOnNAppliquePasDeuxFoisLeMemeLot(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $id = $this->deposer($client, $entete, $this->csv('ANC-50;physique;Vert;Paul;;;;'));
        $client->request('POST', '/api/imports/' . $id . '/apply', $entete);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/imports/' . $id . '/apply', $entete);
        self::assertResponseStatusCodeSame(409);
    }

    /** @param array<string, mixed> $entete */
    private function deposer(object $client, array $entete, string $contenu): string
    {
        $client->request('POST', '/api/imports', $entete + ['json' => [
            'type' => 'customers',
            'fileName' => 'clients.csv',
            'content' => $contenu,
        ]]);
        self::assertResponseIsSuccessful();

        $lot = $client->getResponse()->toArray();
        self::assertSame('validated', $lot['status'], sprintf('Le fichier devait être valide. Erreurs : %s', json_encode($lot['errors'])));

        return $lot['id'];
    }

    private function nombreDeClients(): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return (int) $em->createQueryBuilder()
            ->select('COUNT(c.id)')->from(Client::class, 'c')
            ->getQuery()->getSingleScalarResult();
    }

    private function clientParReference(string $reference): ?Client
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(Client::class)->findOneBy(['externalRef' => $reference]);
    }
}
