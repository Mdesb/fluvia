<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Platform\Notification\NotificationOutcome;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\DebitPreNotification;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Enum\StatutRemiseSepa;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Service\GenererRemiseSepaHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Preuve du recâblage Sport → module SEPA partagé (plan-sepa.md §5) : `GenererRemiseSepaHandler`
 * (Sport) délègue entièrement à `App\Sepa\Service\GenerationRemiseHandler` via le port
 * `SportEcheanceSepaSource` — la remise produite est une **vraie** `App\Sepa\Entity\RemiseSepa` avec
 * un pain.008 réel, l'échéancier fitness Sport est mis à jour (`marquerCollectees`).
 */
final class RemiseSepaRecablageTest extends SportApiTestCase
{
    public function testGenererRemiseProduitUnPain008ReelEtMarqueLesEcheancesCollectees(): void
    {
        [, , $idA] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($idA);
        self::assertNotNull($etablissement);

        $configCreancier = $em->getRepository(ConfigCreancierSepa::class)->findOneBy(['etablissement' => $etablissement]);
        self::assertNotNull($configCreancier, 'Fixture ComptaFixtures/SepaFixtures : config créancier régie requise sur A.');
        self::assertSame('regie', $configCreancier->getVariante()?->value);

        $dateExecution = new \DateTimeImmutable('today');
        $nbEcheancesDuesAvant = \count($em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecution, 'date_immutable')
            ->getQuery()->getResult());
        self::assertGreaterThan(0, $nbEcheancesDuesAvant, 'Le jeu de démonstration Sport doit avoir au moins une échéance due.');

        // Sans préavis, aucune échéance n'est collectable — et c'est voulu. Voir `preavisPour()`.
        $this->preavisPour($em, $etablissement, $dateExecution);

        /** @var GenererRemiseSepaHandler $handler */
        $handler = static::getContainer()->get(GenererRemiseSepaHandler::class);
        $remise = $handler->generer($etablissement, $dateExecution);

        self::assertSame(StatutRemiseSepa::Transmise, $remise->getStatut());
        self::assertSame($nbEcheancesDuesAvant, $remise->getNbTxs());
        self::assertGreaterThan(0, $remise->getCtrlSumCentimes());
        self::assertNotNull($remise->getContenuXml());
        self::assertStringContainsString('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02', (string) $remise->getContenuXml());
        // Établissement A = régie (ProfilExploitant régie directe, ComptaFixtures) : UltmtCdtr attendu.
        self::assertStringContainsString('<UltmtCdtr>', (string) $remise->getContenuXml());

        $em->clear();
        $nbPrelevees = \count($em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :prelevee')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('prelevee', StatutEcheanceSepa::Prelevee->value)
            ->getQuery()->getResult());
        self::assertSame($nbEcheancesDuesAvant, $nbPrelevees, 'EcheanceSepaSource::marquerCollectees a bien mis à jour l\'échéancier Sport.');
    }

    /**
     * Énonce le préavis qu'un prélèvement licite suppose déjà émis.
     *
     * **Pourquoi ce test a cessé de passer, et pourquoi ce n'est pas lui qu'il fallait réparer.**
     * `claude-D` a construit le préavis réglementaire — informer le débiteur du montant et de la date
     * avant chaque prélèvement — puis câblé la remise pour écarter les échéances non couvertes. Ce test
     * s'est mis à échouer, et sa formule est la bonne : **il ne casse pas malgré le changement, il casse
     * parce qu'il décrivait un comportement qui n'était pas licite.** Le module Sport prélevait sans que
     * personne n'ait été prévenu.
     *
     * **Adapter le test sans adapter le chemin réel remettrait le défaut là où il était**, cette fois
     * couvert par un test vert. Ce n'est donc pas ce qu'on fait ici.
     *
     * Un test **énonce un passé cohérent**, exactement comme il énonce qu'un mandat a été signé. Ce qui
     * est fabriqué ici, c'est **l'envoi**, pas la vérification : `covers()` relit ces préavis et contrôle
     * le montant et le délai comme pour n'importe quelle échéance. Un montant faux ou un délai trop court
     * ferait toujours échouer ce test.
     *
     * **⚠ Et le chemin réel n'est PAS réparé — il ne peut pas l'être ici.** Annoncer au moment de
     * générer ne satisferait jamais le délai de quatorze jours : `sentAt` serait aujourd'hui. Le préavis
     * relève d'une tâche planifiée qui annonce les échéances à venir, **et cette tâche n'existe pas** :
     * `App\Sepa` ne déclare aucune commande, et le catalogue d'ordonnancement ne connaît aucun préavis.
     * En production, aucune échéance ne serait donc jamais couverte, indéfiniment.
     *
     * C'est le motif du dépôt sous sa forme la plus coûteuse : **le mécanisme existe, l'appel manque.**
     * Signalé à `claude-D`, à qui `App\Sepa` appartient.
     */
    private function preavisPour(
        EntityManagerInterface $em,
        \App\Organisation\Entity\Etablissement $etablissement,
        \DateTimeImmutable $dateExecution,
    ): void {
        $echeances = $em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecution, 'date_immutable')
            ->getQuery()->getResult();

        foreach ($echeances as $echeance) {
            $mandat = $echeance->getAbonnement()?->getMandatSepa();

            if ($mandat === null) {
                continue;
            }

            $preavis = (new DebitPreNotification())
                ->setMandate($mandat)
                // La référence d'origine est celle que `SportEcheanceSepaSource` transmet : l'identifiant
                // de l'échéance. Un autre choix ici et `covers()` ne rapprocherait rien.
                ->setOriginReference((string) $echeance->getId())
                ->setAmountCents($echeance->getMontantCentimes())
                ->setAnnouncedDueDate($echeance->getDateProgrammee())
                ->setSentAt($dateExecution->modify('-20 days'))
                ->setReason(PreNotificationReason::Schedule)
                ->setOutcome(NotificationOutcome::Envoyee);

            $em->persist($preavis);
        }

        $em->flush();
    }
}
