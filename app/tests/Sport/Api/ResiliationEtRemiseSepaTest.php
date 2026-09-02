<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Organisation\Entity\Etablissement;
use App\Platform\Notification\NotificationOutcome;
use App\Sepa\Entity\DebitPreNotification;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Service\DemanderResiliationHandler;
use App\Sport\Service\GenererRemiseSepaHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * ⚠ UN ADHÉRENT QUI RÉSILIAIT ÉTAIT ENCORE PRÉLEVÉ, ET RIEN NE LE DISAIT.
 *
 * Ce test ne décrit pas une protection future : il cloue un défaut qui prenait de l'argent.
 *
 * `DemanderResiliationHandler::executerEffet()` passe le mandat à `Revoque` quand plus aucun
 * abonnement ne s'y appuie. Mais **rien n'annule les échéances restantes**. Les sorties de
 * `StatutEcheanceSepa::AVenir` sont `Prelevee`, `Rejetee`, `Gelee` et `Annulee` ; cette dernière
 * n'est posée que par un geste MANUEL (`POST /sport/echeances/{id}/annuler`), jamais par la
 * résiliation, et le seul écouteur sur `Resiliation` est le journal d'audit. Or
 * `SportEcheanceSepaSource` JOINT
 * le mandat (`->join('a.mandatSepa', 'm')`) pour en tirer l'identifiant, sans jamais filtrer dessus.
 *
 * L'adhérent qui résiliait voyait donc son autorisation retirée, et son compte débité à la remise
 * suivante. Le contraste était dans le dépôt : `ReservationEcheanceSepaSource` cherche
 * `['statut' => Actif]`, et `CardDebitFallback` refuse un mandat non actif. Le chemin principal du
 * prélèvement était le seul à ne rien regarder.
 *
 * **La seconde moitié de ce test est celle qui le rend concluant.** Une remise vide peut l'être pour
 * quantité de raisons — pas de préavis, pas d'échéance due, pas de configuration créancier. On remet
 * donc le mandat actif et on régénère : mêmes échéances, mêmes préavis, même date d'exécution, et
 * cette fois la remise se compose. Le refus venait bien du statut du mandat, et de rien d'autre.
 */
final class ResiliationEtRemiseSepaTest extends SportApiTestCase
{
    public function testUnAdherentQuiResilieNEstPlusPreleve(): void
    {
        [, , $idA] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(Etablissement::class)->find($idA);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $abonnement = $this->abonnementDemo();
        $mandatId = (string) $abonnement->getMandatSepa()->getId();
        $dateExecution = new \DateTimeImmutable('today');

        $nbDues = $this->nbEcheancesDues($em, $etablissement, $dateExecution);
        self::assertGreaterThan(0, $nbDues, 'Le jeu Sport doit avoir au moins une échéance due, sinon ce test ne mesure rien.');

        // Le préavis est posé AVANT la résiliation : ainsi, ce qui exclura les échéances plus bas ne
        // pourra pas être un défaut de préavis.
        $this->preavisPour($em, $etablissement, $dateExecution);

        // ─── La résiliation réelle, par son propre handler ─────────────────────────────────────
        /** @var DemanderResiliationHandler $resiliations */
        $resiliations = static::getContainer()->get(DemanderResiliationHandler::class);
        $resiliation = $resiliations->demander(
            $abonnement,
            $abonnement->getDateFinEngagement()->modify('+1 day'),
            'Test — départ de l’adhérent',
            false,
            null,
        );
        $resiliations->executerEffet($resiliation);
        $em->clear();

        // ⚠ RECHARGER APRÈS `clear()`. Sans cette ligne, `$etablissement` reste détaché et la remise
        // le référence comme une entité neuve : Doctrine lève « A new entity was found through the
        // relationship RemiseSepa#etablissement », une erreur de harnais qui ressemble à un défaut
        // du code mesuré.
        $etablissement = $em->getRepository(Etablissement::class)->find($idA);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $mandat = $em->getRepository(MandatSepa::class)->find($mandatId);
        self::assertInstanceOf(MandatSepa::class, $mandat);
        self::assertSame(
            StatutMandatSepa::Revoque,
            $mandat->getStatut(),
            'Préalable de ce test : la résiliation révoque le mandat. Si ce n’est plus le cas, ce test '
            .'ne mesure plus la route qu’il prétend mesurer.',
        );

        // ⚠ ET LES ÉCHÉANCES N'ONT PAS BOUGÉ. C'est exactement ce qui rendait le défaut vivant :
        // l'autorisation est retirée, la dette reste programmée, et rien ne les rapproche.
        self::assertSame(
            $nbDues,
            $this->nbEcheancesDues($em, $etablissement, $dateExecution),
            'La résiliation n’annule aucune échéance — c’est la moitié du défaut. Si elle en annulait '
            .'désormais, ce test devrait être réécrit plutôt que corrigé.',
        );

        // ─── Moitié 1 : la remise refuse, et nomme la cause ────────────────────────────────────
        /** @var GenererRemiseSepaHandler $remises */
        $remises = static::getContainer()->get(GenererRemiseSepaHandler::class);

        try {
            $remises->generer($etablissement, $dateExecution);
            self::fail(
                'Un adhérent qui a résilié vient d’être prélevé. Son mandat est révoqué et ses '
                .'échéances sont malgré tout entrées dans la remise.',
            );
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString(
                'mandat non actif',
                $e->getMessage(),
                'Le refus ne nomme pas le mandat : le message enverra chercher la cause ailleurs.',
            );
            self::assertStringNotContainsString(
                'faute de préavis',
                $e->getMessage(),
                'Le message impute encore l’exclusion au préavis, alors que le préavis est en règle ici.',
            );
        }

        // ─── Moitié 2 : LE TÉMOIN POSITIF, sans lequel le refus ci-dessus ne prouve rien ───────
        //
        // On remet le mandat actif — manipulation de test : aucun chemin du produit ne réactive un
        // mandat, et c'est délibéré. Rien d'autre ne change : mêmes échéances, mêmes préavis, même
        // date. Si la remise se compose maintenant, le refus venait du statut et de rien d'autre.
        $mandat->setStatut(StatutMandatSepa::Actif);
        $em->flush();

        $remise = $remises->generer($etablissement, $dateExecution);
        self::assertSame(
            $nbDues,
            $remise->getNbTxs(),
            'Mandat actif, préavis en règle : toutes les échéances dues devaient entrer dans la remise.',
        );
        self::assertSame(
            0,
            $remise->getNbExclues(),
            'Rien ne devait être écarté une fois le mandat actif — surtout pas faute de préavis.',
        );
    }

    private function nbEcheancesDues(
        EntityManagerInterface $em,
        Etablissement $etablissement,
        \DateTimeImmutable $dateExecution,
    ): int {
        return \count($em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecution, 'date_immutable')
            ->getQuery()->getResult());
    }

    /**
     * Énonce le préavis qu'un prélèvement licite suppose déjà émis — même forme que
     * `RemiseSepaRecablageTest`, où la raison d'être de ce fait passé est expliquée en détail.
     *
     * Ce qui est fabriqué ici, c'est **l'envoi**, pas la vérification : `covers()` relit ces préavis
     * et contrôle le montant et le délai comme pour n'importe quelle échéance.
     */
    private function preavisPour(
        EntityManagerInterface $em,
        Etablissement $etablissement,
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

            $em->persist((new DebitPreNotification())
                ->setMandate($mandat)
                ->setOriginReference((string) $echeance->getId())
                ->setAmountCents($echeance->getMontantCentimes())
                ->setAnnouncedDueDate($echeance->getDateProgrammee())
                ->setSentAt($dateExecution->modify('-20 days'))
                ->setReason(PreNotificationReason::Schedule)
                ->setOutcome(NotificationOutcome::Envoyee));
        }

        $em->flush();
    }
}
