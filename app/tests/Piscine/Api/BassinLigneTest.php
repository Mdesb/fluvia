<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Piscine\DataFixtures\PiscineFixtures;
use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\LigneEau;
use App\Tests\Piscine\PiscineApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bassins & lignes d'eau (US-L6-05) et créneaux publics multiples (RG-PISC-03, US-L6-06) : CA-5, CA-6.
 */
final class BassinLigneTest extends PiscineApiTestCase
{
    public function testCa5ReservationDepassantLaCapaciteDisponibleRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$creneauId, $ligne1, ] = $this->creerCreneauEtLignes($client, $entete);

        // Capacité du bassin de démonstration = 60 (plan §5, plan-piscine.md).
        $client->request('POST', '/api/creneau_publics', $entete + [
            'json' => [
                'creneauBassin' => '/api/creneau_bassins/' . $creneauId,
                'typePublic' => 'club',
                'lignes' => ['/api/ligne_eaus/' . $ligne1],
                'jauge' => 999,
            ],
        ]);
        self::assertResponseStatusCodeSame(422, 'US-L6-05 : une réservation dépassant la capacité disponible est refusée.');
    }

    public function testCa5CompteurBassinDistinctDeLaFmiEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $espaceAcces = $this->entite(EspaceAcces::class, ['libelle' => \App\Acces\DataFixtures\AccesFixtures::ESPACE_LIBELLE]);
        $jaugeAvant = $em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espaceAcces]);
        self::assertSame(0, $jaugeAvant->getValeurCourante());

        [$creneauId, $ligne1, ] = $this->creerCreneauEtLignes($client, $entete);

        $client->request('POST', '/api/creneau_publics', $entete + [
            'json' => [
                'creneauBassin' => '/api/creneau_bassins/' . $creneauId,
                'typePublic' => 'club',
                'lignes' => ['/api/ligne_eaus/' . $ligne1],
                'jauge' => 5,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $jaugeApres = $em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espaceAcces]);
        self::assertSame(0, $jaugeApres->getValeurCourante(), 'La réservation de lignes ne touche pas la FMI établissement (US-L6-05).');
    }

    public function testCa6DeuxPublicsSurLignesDistinctesAcceptesAvecJaugesIndependantes(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$creneauId, $ligne1, $ligne2, $ligne3, $ligne4] = $this->creerCreneauEtLignes($client, $entete);

        $client->request('POST', '/api/creneau_publics', $entete + [
            'json' => [
                'creneauBassin' => '/api/creneau_bassins/' . $creneauId,
                'typePublic' => 'club',
                'lignes' => ['/api/ligne_eaus/' . $ligne1, '/api/ligne_eaus/' . $ligne2],
                'jauge' => 10,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/creneau_publics', $entete + [
            'json' => [
                'creneauBassin' => '/api/creneau_bassins/' . $creneauId,
                'typePublic' => 'grand_public',
                'lignes' => ['/api/ligne_eaus/' . $ligne3, '/api/ligne_eaus/' . $ligne4],
                'jauge' => 15,
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(15, $client->getResponse()->toArray()['jauge'], 'Chaque public conserve sa propre jauge, indépendante des autres (CA-6).');
    }

    public function testCa7JaugeGrandPublicRecalculeeAuProrataEtExposeeEnTempsReel(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$creneauId, $ligne1, ] = $this->creerCreneauEtLignes($client, $entete);

        // 1 ligne sur 4 réservée par un club : capacité restante = round(60 × (1 − 1/4)) = 45.
        $client->request('POST', '/api/creneau_publics', $entete + [
            'json' => [
                'creneauBassin' => '/api/creneau_bassins/' . $creneauId,
                'typePublic' => 'club',
                'lignes' => ['/api/ligne_eaus/' . $ligne1],
                'jauge' => 5,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/jauge_grand_public_calculees', $entete + ['query' => ['itemsPerPage' => 50]]);
        self::assertResponseIsSuccessful();
        $membres = $client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'];
        // `creneauBassin` peut être sérialisé en IRI (string) ou en sous-ressource (array) selon les
        // groupes partagés ; on compare la représentation JSON, robuste aux deux formes.
        $jauges = array_filter($membres, static fn (array $j) => str_contains(json_encode($j['creneauBassin']), $creneauId));
        self::assertNotEmpty($jauges, 'US-L6-07 : la jauge grand public recalculée est exposée en temps réel via l\'API.');
        self::assertSame(45, array_values($jauges)[0]['capaciteRestante']);
    }

    public function testCa6AffectationChevauchanteRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$creneauId, $ligne1, $ligne2, ] = $this->creerCreneauEtLignes($client, $entete);

        $client->request('POST', '/api/creneau_publics', $entete + [
            'json' => [
                'creneauBassin' => '/api/creneau_bassins/' . $creneauId,
                'typePublic' => 'club',
                'lignes' => ['/api/ligne_eaus/' . $ligne1],
                'jauge' => 5,
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Même ligne (1), créneau bassin qui chevauche temporellement (même créneau ici) -> refus.
        $client->request('POST', '/api/creneau_publics', $entete + [
            'json' => [
                'creneauBassin' => '/api/creneau_bassins/' . $creneauId,
                'typePublic' => 'scolaire',
                'lignes' => ['/api/ligne_eaus/' . $ligne1, '/api/ligne_eaus/' . $ligne2],
                'jauge' => 5,
            ],
        ]);
        self::assertResponseStatusCodeSame(422, 'RG-PISC-03 : une ligne ne peut être affectée qu\'à un seul public à la fois.');
    }

    /**
     * Crée un créneau bassin sur le bassin de démonstration (4 lignes, plan-piscine.md fixtures).
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} idCreneau, ligne1..4
     */
    private function creerCreneauEtLignes(object $client, array $entete): array
    {
        $bassin = $this->entite(Bassin::class, ['libelle' => PiscineFixtures::BASSIN_LIBELLE]);

        $client->request('POST', '/api/creneau_bassins', $entete + [
            'json' => [
                'bassin' => '/api/bassins/' . $bassin->getId(),
                'debut' => '2026-09-01T09:00:00+02:00',
                'fin' => '2026-09-01T10:00:00+02:00',
                'encadrantRequis' => 'aucune',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $creneauId = $client->getResponse()->toArray()['id'];

        $lignes = [];
        for ($numero = 1; $numero <= 4; ++$numero) {
            $lignes[] = (string) $this->entite(LigneEau::class, ['bassin' => $bassin, 'numero' => $numero])->getId();
        }

        return [$creneauId, ...$lignes];
    }
}
