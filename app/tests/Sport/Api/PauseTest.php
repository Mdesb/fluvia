<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pause d'abonnement (US-SPORT-02, RG-SPORT-05, décision actée, CA-2) : échéancier gelé, fin
 * d'engagement reportée de la durée exacte, pause refusée si un impayé est en cours.
 */
final class PauseTest extends SportApiTestCase
{
    public function testCa2PauseGeleLEcheancierEtReporteLaFinDEngagement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnement = $this->abonnementDemo();
        $abonnementId = (string) $abonnement->getId();
        $finEngagementInitiale = $abonnement->getDateFinEngagement();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var list<EcheanceSepa> $echeancesAVenir */
        $echeancesAVenir = $em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->andWhere('e.abonnement = :a')
            ->setParameter('a', $abonnementId, 'uuid')
            ->orderBy('e.dateProgrammee', 'ASC')
            ->getQuery()->getResult();
        self::assertGreaterThanOrEqual(2, count($echeancesAVenir), 'Au moins 2 échéances requises pour ce scénario.');

        // Encadre les 2 premières échéances à venir dans la fenêtre de pause.
        $debut = $echeancesAVenir[0]->getDateProgrammee();
        $fin = $echeancesAVenir[1]->getDateProgrammee()->modify('+1 day');
        $joursPause = (int) $debut->diff($fin)->days;

        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/pauses', $entete + [
            'json' => ['dateDebut' => $debut->format('Y-m-d'), 'dateFin' => $fin->format('Y-m-d'), 'motif' => 'Voyage prolongé'],
        ]);
        self::assertResponseIsSuccessful();
        $pause = $client->getResponse()->toArray();
        self::assertSame('active', $pause['statut']);

        $em->clear();

        $abonnementRafraichi = $em->getRepository(AbonnementFitness::class)->find($abonnementId);
        self::assertSame('pause', $abonnementRafraichi->getStatut()->value);

        // Report = durée exacte de la pause (dateFin - dateDebut).
        self::assertSame($finEngagementInitiale->modify(sprintf('+%d days', $joursPause))->format('Y-m-d'), $abonnementRafraichi->getDateFinEngagement()->format('Y-m-d'));

        // Aucun prélèvement émis pour les échéances gelées (RG-SPORT-05).
        $echeance1 = $em->getRepository(EcheanceSepa::class)->find($echeancesAVenir[0]->getId());
        $echeance2 = $em->getRepository(EcheanceSepa::class)->find($echeancesAVenir[1]->getId());
        self::assertSame(StatutEcheanceSepa::Gelee, $echeance1->getStatut());
        self::assertSame(StatutEcheanceSepa::Gelee, $echeance2->getStatut());
    }

    public function testCa2PauseRefuseeSiImpayeEnCours(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnement = $this->abonnementDemo();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement->setStatut(StatutAbonnementFitness::Impaye);
        $em->flush();

        $debut = new \DateTimeImmutable('+15 days');
        $fin = $debut->modify('+30 days');

        $client->request('POST', '/api/sport/abonnements/' . $abonnement->getId() . '/pauses', $entete + [
            'json' => ['dateDebut' => $debut->format('Y-m-d'), 'dateFin' => $fin->format('Y-m-d')],
        ]);
        self::assertResponseStatusCodeSame(422, 'Pause refusée tant que l\'impayé n\'est pas régularisé (décision actée).');
    }
}
