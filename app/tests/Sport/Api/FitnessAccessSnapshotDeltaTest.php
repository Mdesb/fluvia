<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Acces\Enum\TypeDroitAcces;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Enum\MotifInactiviteAccesFitness;
use App\Membership\Service\PropagationAccesFitnessHandler;
use App\Tests\Acces\SnapshotDeltaTrait;
use App\Tests\Sport\SportApiTestCase;

/**
 * Pause et reprise d'un abonnement fitness : la borne synchronisée en delta doit le voir.
 *
 * `PropagationAccesFitnessHandler` écrit `DroitAcces.statutProjection` (pause, résiliation, rejeu au
 * rattachement). Avant le mécanisme central, il ne faisait pas avancer `Support.versionMaj` : la base
 * disait « en pause », la borne hors ligne ouvrait toujours. Le test passe par le handler lui-même,
 * pas par une écriture du test sur la colonne.
 */
final class FitnessAccessSnapshotDeltaTest extends SportApiTestCase
{
    use SnapshotDeltaTrait;

    public function testPauseEtRepriseAtteignentLeDelta(): void
    {
        [$droitId, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::Abonnement);
        $abonnementId = $this->abonnementDemo()->getId();

        $em = $this->snapshotEm();
        $abonnement = $em->getRepository(Membership::class)->find($abonnementId);
        $statut = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement]);
        self::assertInstanceOf(StatutAccesFitness::class, $statut, 'témoin : l\'abonnement de démonstration porte un statut d\'accès');
        $statut->setDroitAcces($this->findRight($droitId));
        $em->flush();

        $curseur = $this->snapshotCursor();
        $em = $this->snapshotEm();
        static::getContainer()->get(PropagationAccesFitnessHandler::class)
            ->desactiver($em->getRepository(Membership::class)->find($abonnementId), MotifInactiviteAccesFitness::Pause);
        self::assertTrue($this->assertInDelta($curseur, $identifiant, 'PropagationAccesFitnessHandler::desactiver')['revoque'], 'Pause : la borne doit fermer.');

        $curseur = $this->snapshotCursor();
        $em = $this->snapshotEm();
        static::getContainer()->get(PropagationAccesFitnessHandler::class)
            ->activer($em->getRepository(Membership::class)->find($abonnementId));
        self::assertFalse($this->assertInDelta($curseur, $identifiant, 'PropagationAccesFitnessHandler::activer')['revoque'], 'Reprise : la borne doit rouvrir.');
    }
}
