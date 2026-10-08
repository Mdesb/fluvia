<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Recurrence;
use App\Reservation\Entity\Ressource;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN COURS DE 18:00 POSÉ EN SEPTEMBRE EST À 18:00 EN NOVEMBRE, DE BOUT EN BOUT.
 *
 * Par l'API, comme l'écran : le début arrive en UTC (`toISOString()`), la base garde des instants
 * UTC. Les occurrences étaient posées à la même heure UTC que la première, donc une heure plus tôt
 * à Paris après le 25/10/2026.
 */
final class DaylightSavingSlotTest extends ReservationApiTestCase
{
    public function testAWeeklySessionPostedInSeptemberIsAtSixPmInParisInNovember(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->freshResource()->getId(),
                'debut' => '2026-09-17T16:00:00.000Z', // jeudi 18:00 à Paris, ce qu'envoie l'écran.
                'fin' => '2026-09-17T17:00:00.000Z',
                'capacite' => 4,
                'recurrence' => ['motif' => 'hebdomadaire', 'finRecurrence' => '2026-11-26T22:59:59.000Z', 'regleConflit' => 'validation_manuelle'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $recurrence = $client->getResponse()->toArray()['recurrence'] ?? null;
        $recurrenceId = \is_array($recurrence) ? (string) $recurrence['id'] : basename((string) $recurrence);

        $em = $this->em();
        $em->clear();
        $occurrences = $em->getRepository(Creneau::class)->findBy(['recurrence' => $em->getRepository(Recurrence::class)->find($recurrenceId)], ['debut' => 'ASC']);
        $utc = array_map(static fn (Creneau $c): string => $c->getDebut()->format('Y-m-d H:i'), $occurrences);

        self::assertCount(11, $utc, 'Onze jeudis du 17/09 au 26/11.');
        self::assertContains('2026-10-22 16:00', $utc, 'Témoin : avant le 25/10, 18:00 à Paris est 16:00 UTC.');
        self::assertContains('2026-11-05 17:00', $utc, 'Après le 25/10, 18:00 à Paris est 17:00 UTC : la séance ne doit pas glisser à 17:00 à Paris.');
        foreach ($occurrences as $c) {
            self::assertSame('18:00', $this->paris($c->getDebut())->format('H:i'), 'Le ' . $c->getDebut()->format('d/m') . ', la séance a glissé.');
        }
    }

    /**
     * Un début envoyé avec son décalage (« 18:00+02:00 ») est stocké en UTC. Doctrine écrit l'heure
     * murale de l'objet sans conversion : sans normalisation, 18:00+02:00 devenait 18:00 UTC.
     */
    public function testAStartSentWithAnOffsetIsStoredInUtc(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->freshResource()->getId(),
                'debut' => '2026-09-24T18:00:00+02:00',
                'fin' => '2026-09-24T19:00:00+02:00',
                'capacite' => 4,
                'recurrence' => ['motif' => 'hebdomadaire', 'finRecurrence' => '2026-10-29'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $id = (string) $client->getResponse()->toArray()['id'];

        $stored = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT c.debut FROM reservation_creneau c WHERE c.recurrence_id = (SELECT recurrence_id FROM reservation_creneau WHERE id = :id) ORDER BY c.debut',
            ['id' => \Symfony\Component\Uid\Uuid::fromString($id)->toBinary()],
        );
        self::assertSame(
            ['2026-09-24 16:00:00', '2026-10-01 16:00:00', '2026-10-08 16:00:00', '2026-10-15 16:00:00', '2026-10-22 16:00:00', '2026-10-29 17:00:00'],
            $stored,
        );
    }

    private function freshResource(): Ressource
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);
        self::assertSame('Europe/Paris', $etablissement->getFuseauHoraire(), 'Précondition : l\'établissement A vit à Paris.');

        $resource = (new Ressource())->setEtablissement($etablissement)->setCodeType('salle')
            ->setLibelle('Salle ' . bin2hex(random_bytes(3)))->setCapacitePropre(4);
        $em->persist($resource);
        $em->flush();

        return $resource;
    }

    private function paris(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone('Europe/Paris'));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
