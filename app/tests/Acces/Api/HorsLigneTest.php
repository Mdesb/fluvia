<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\DroitAcces;
use App\Tests\Acces\AccesApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Hors-ligne + resynchronisation (US-L3-07/08, RG-ACC-05, CA-8/9) : rejeu chronologique, anti-doublon
 * (clé rejouée = no-op), crédits/FMI recalés « en marchant », révocation postérieure détectée et
 * tracée sans annulation rétroactive du passage déjà advenu.
 */
final class HorsLigneTest extends AccesApiTestCase
{
    public function testCa9RejeuChronologiqueEtAntiDoublon(): void
    {
        [$client, $entete] = $this->adminSurA();

        $cle = (string) Uuid::v4();
        $lot = [
            'controleur' => '/api/controleurs/' . $this->idControleur(),
            'lot' => [[
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'sens' => 'entree',
                'horodatage' => (new \DateTimeImmutable('2026-06-01T09:00:00+00:00'))->format(DATE_ATOM),
                'cleIdempotence' => $cle,
            ]],
        ];

        $premier = $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot])->toArray();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $premier['inseres']);
        self::assertEmpty($premier['doublons']);

        // Rejeu du même lot : la clé déjà remontée est un no-op (anti-doublon, sans double décompte).
        $second = $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot])->toArray();
        self::assertEmpty($second['inseres']);
        self::assertContains($cle, $second['doublons']);

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(11, $droit->getCreditRestant(), 'Le crédit ne doit être décompté qu\'une seule fois (idempotence).');
    }

    public function testCa9RejeuHorodateChronologiquementMalgreOrdreDArrivee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $base = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');
        // Envoyés dans le désordre (t+600 avant t+0) : le rejeu doit les traiter chronologiquement,
        // donc t+0 (dans les marges anti-passback) puis t+600 (hors délai) = tous deux valides.
        $lot = [
            'controleur' => '/api/controleurs/' . $this->idControleur(),
            'lot' => [
                [
                    'equipementId' => $this->idEquipement(),
                    'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                    'sens' => 'entree',
                    'horodatage' => $base->modify('+600 seconds')->format(DATE_ATOM),
                    'cleIdempotence' => (string) Uuid::v4(),
                ],
                [
                    'equipementId' => $this->idEquipement(),
                    'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                    'sens' => 'entree',
                    'horodatage' => $base->format(DATE_ATOM),
                    'cleIdempotence' => (string) Uuid::v4(),
                ],
            ],
        ];

        $resultat = $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot])->toArray();
        self::assertCount(2, $resultat['inseres']);

        $client->request('GET', '/api/passages', $entete + ['query' => ['order[horodatage]' => 'asc']]);
        $passages = $client->getResponse()->toArray();
        $membres = $passages['member'] ?? $passages['hydra:member'];
        self::assertCount(2, $membres);
        self::assertLessThan($membres[1]['horodatage'], $membres[0]['horodatage']);
    }

    /** CA-9 — Révocation postérieure au passage hors-ligne : conflit tracé, passage conservé (non annulé). */
    public function testCa9RevocationPosterieureDetecteeEtTraceeSansAnnulation(): void
    {
        [$client, $entete] = $this->adminSurA();

        $horodatagePassage = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');

        // Blocage serveur survenant APRÈS l'instant du passage hors-ligne (mais avant la synchro).
        $client->request('POST', '/api/acces/supports/' . $this->idSupport() . '/bloquer', $entete + [
            'json' => ['motif' => 'Révoqué après le passage hors-ligne'],
        ]);
        self::assertResponseIsSuccessful();

        $lot = [
            'controleur' => '/api/controleurs/' . $this->idControleur(),
            'lot' => [[
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'sens' => 'entree',
                'horodatage' => $horodatagePassage->format(DATE_ATOM),
                'cleIdempotence' => (string) Uuid::v4(),
            ]],
        ];
        $resultat = $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot])->toArray();
        self::assertCount(1, $resultat['inseres']);
        self::assertCount(1, $resultat['conflits'], 'Le conflit (révocation postérieure) doit être détecté et tracé.');

        $client->request('GET', '/api/passages/' . $resultat['inseres'][0], $entete);
        $passage = $client->getResponse()->toArray();
        self::assertSame('valide', $passage['resultat'], 'Le passage déjà advenu physiquement est conservé, non annulé rétroactivement.');
        self::assertTrue($passage['enConflit']);
    }
}
