<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\Calendar\Entity\CalendarEvent;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Securite\SecuriteApiTestCase;

/**
 * L'EN-TÊTE `X-Etablissement` N'EST PLUS UN LIBRE-SERVICE — audit du 06/09, constat 3.
 *
 * `AxeEtablissementActifTest` prouve qu'un en-tête hors périmètre est refusé sur une opération à droit
 * FIN : c'est `PermissionVoter` qui ferme. Ce fichier prouve le reste : les opérations qui n'exigent
 * que d'être connecté — l'agenda, ici — sont fermées aussi, par `EstablishmentHeaderListener`, et de la
 * même façon (404).
 *
 * Le lecteur est affecté à A seulement. Il nomme B.
 */
final class EstablishmentHeaderListenerTest extends SecuriteApiTestCase
{
    public function testUnEnteteHorsPerimetreVaut404SurUneOperationSansDroitFin(): void
    {
        [$client, $token] = $this->lecteur();

        $client->request('GET', '/api/calendar/calendar_events', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * L'EXPLOIT EXACT DE L'AUDIT, rejoué : un événement PERSONNEL — donc sans le droit fin que
     * `CalendarEventProcessor` n'exige que pour les événements du site — écrit chez le voisin.
     * Mesuré en préproduction le 06/09 : HTTP 201, `establishment_id` de B en base.
     */
    public function testUnInconnuDuSiteNYEcritPasUnEvenementPersonnel(): void
    {
        [$client, $token] = $this->lecteur();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $client->request('POST', '/api/calendar/calendar_events', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idB],
            'json' => ['title' => 'PREUVE constat 3', 'start' => '2026-09-20T10:00:00+02:00', 'end' => '2026-09-20T11:00:00+02:00'],
        ]);

        self::assertResponseStatusCodeSame(404);

        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $ecrits = static::getContainer()->get('doctrine')->getManager()
            ->getRepository(CalendarEvent::class)->findBy(['establishment' => $etabB, 'title' => 'PREUVE constat 3']);
        self::assertSame([], $ecrits, 'rien ne doit avoir été écrit chez B');
    }

    /** Ce que le listener ÉPARGNE : son propre site reste atteignable, sans quoi les autres tests ne prouveraient rien. */
    public function testSonPropreSiteResteAtteignable(): void
    {
        [$client, $token] = $this->lecteur();

        $client->request('GET', '/api/calendar/calendar_events', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ]);

        self::assertResponseIsSuccessful();
    }

    /** Un administrateur affecté à A ET à B atteint B : la règle mesure l'affectation, pas le rôle. */
    public function testUnAdministrateurAffecteAuxDeuxSitesAtteintLesDeux(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $client->request('GET', '/api/calendar/calendar_events', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * /me EST LA SEULE ROUTE EXEMPTÉE — c'est par elle que l'écran se remet d'un établissement mémorisé
     * qui ne lui appartient plus. Mais elle ne SERT pas le site étranger pour autant : il est traité
     * comme absent.
     */
    public function testMeNeRefusePasMaisNeReconnaitPasLeSiteEtranger(): void
    {
        [$client, $token] = $this->lecteur();

        $reponse = $client->request('GET', '/me', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);

        self::assertResponseIsSuccessful();
        $profil = $reponse->toArray();
        self::assertNull($profil['etablissementActif'], 'un site hors périmètre n\'est jamais rendu comme actif');
        self::assertSame([], $profil['capacitesActives']);
        self::assertFalse($profil['estEditeur']);
    }

    /** Témoin de l'exemption : /me avec SON site rend bien ce site — sinon le test précédent passerait sur un /me toujours vide. */
    public function testMeAvecSonPropreSiteLeRend(): void
    {
        [$client, $token] = $this->lecteur();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $profil = $client->request('GET', '/me', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ])->toArray();

        self::assertSame($idA, $profil['etablissementActif']);
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: string} */
    private function lecteur(): array
    {
        $client = static::createClient();

        return [$client, $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP)];
    }
}
