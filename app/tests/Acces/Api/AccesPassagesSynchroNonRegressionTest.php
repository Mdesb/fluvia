<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\DroitAcces;
use App\Tests\Acces\AccesApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Non-régression explicite (plan-acces-terminal.md §6) : `POST /acces/passages` et `POST /acces/synchro`
 * produisent des réponses et effets de bord strictement identiques à l'existant (`plan-acces.md`) après
 * l'introduction du module terminal — opération, security, processor, forme de la réponse inchangés.
 */
final class AccesPassagesSynchroNonRegressionTest extends AccesApiTestCase
{
    public function testAccesPassagesReponseInchangeeEtCreditDecrementeNormalement(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        $corps = $reponse->toArray();

        // Forme du contrat historique inchangée (aucun champ « message »/« affichage » ajouté ici :
        // ceux-ci n'existent que sur /terminal/passages, cf. TerminalPassageProcessor).
        self::assertSame(['id', 'resultat', 'codeMotif', 'motif', 'horodatage', 'propositionRecharge'], array_keys($corps));
        self::assertSame('valide', $corps['resultat']);
        self::assertNull($corps['codeMotif']);

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(11, $droit->getCreditRestant());
    }

    public function testAccesPassagesCreditEpuiseRefuseSansCreditNegatifNiJournalReconciliation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $equipement = '/api/equipements/' . $this->idEquipement();
        $base = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');

        for ($i = 0; $i < 12; ++$i) {
            $client->request('POST', '/api/acces/passages', $entete + [
                'json' => [
                    'equipement' => $equipement,
                    'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                    'horodatage' => $base->modify(sprintf('+%d seconds', $i * 301))->format(DATE_ATOM),
                ],
            ]);
        }

        $reponse = $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => $equipement,
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'horodatage' => $base->modify(sprintf('+%d seconds', 12 * 301))->format(DATE_ATOM),
            ],
        ]);
        $corps = $reponse->toArray();
        self::assertSame('refuse', $corps['resultat']);
        self::assertSame('credit_epuise', $corps['codeMotif']);

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(0, $droit->getCreditRestant(), 'Jamais négatif sur /acces/passages (flux en ligne, flag toujours false).');
    }

    public function testAccesSynchroReponseEtIdempotenceInchangees(): void
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
        self::assertSame(['controleur', 'recus', 'inseres', 'doublons', 'conflits'], array_keys($premier));
        self::assertCount(1, $premier['inseres']);
        self::assertEmpty($premier['doublons']);
        self::assertEmpty($premier['conflits']);

        $second = $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot])->toArray();
        self::assertEmpty($second['inseres']);
        self::assertContains($cle, $second['doublons']);

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(11, $droit->getCreditRestant(), 'Un seul décompte (idempotence inchangée).');
    }
}
