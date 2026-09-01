<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Command;

use App\Finance\Treasury\Entity\TreasuryCashAlert;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Finance\Treasury\Enum\CashAlertStatus;
use App\Finance\Treasury\Service\ThresholdBreachProjectionCalculator;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * `finance:treasury:verifier-seuils` (addendum FIN-4, RG-TRE-11/13, §0.5 du plan) — patron identique à
 * `finance:treasury:detecter-ecarts` : parcourt tous les `TreasurySettings.cashAlertThresholdCents`
 * **non nuls** (jointure implicite vers `establishment`), jamais `ContexteEtablissement::idActif()`
 * (D6, la commande n'a d'ailleurs aucun contexte HTTP).
 *
 * Pour chaque établissement concerné :
 * 1. Projette le franchissement (`ThresholdBreachProjectionCalculator::projeter()`).
 * 2. **Aucun franchissement** et une alerte `open` existe -> résolution silencieuse (`resolved`,
 *    `resolvedAt = now`), **aucun** événement.
 * 3. **Franchissement, aucune alerte `open`** -> nouvelle `TreasuryCashAlert`, émission de
 *    `treasury.threshold_breached`.
 * 4. **Franchissement, une alerte `open` existe** -> compare la date projetée à celle **actuellement
 *    stockée** (qui reflète toujours la dernière date connue, silencieuse ou notifiante, §0.5 du plan) :
 *    identique ou plus tardive -> mise à jour silencieuse ; strictement plus tôt (aggravation) -> mise à
 *    jour **+** nouvel événement sur la **même** entité. `thresholdCentsAtDetection`/
 *    `horizonDaysAtDetection` restent inchangés dans les deux cas (copiés une seule fois, à la création).
 *
 * Chaque établissement est traité dans sa **propre** transaction (`wrapInTransaction`, flush + publish
 * réunis, même patron que `DetecterEcartsCommand`) : un abonné qui échoue sur un établissement
 * n'empêche pas les suivants d'être traités.
 */
#[AsCommand(
    name: 'finance:treasury:verifier-seuils',
    description: 'Détecte le franchissement projeté d\'un seuil de trésorerie configuré et émet `treasury.threshold_breached`.',
)]
final class CheckThresholdsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThresholdBreachProjectionCalculator $calculator,
        private readonly EventBus $eventBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<TreasurySettings> $reglages */
        $reglages = $this->em->createQueryBuilder()
            ->select('s')
            ->from(TreasurySettings::class, 's')
            ->andWhere('s.cashAlertThresholdCents IS NOT NULL')
            ->getQuery()->getResult();

        $maintenant = new \DateTimeImmutable();
        $aujourdhui = new \DateTimeImmutable('today');
        $creees = 0;
        $notifiees = 0;
        $resolues = 0;

        foreach ($reglages as $reglage) {
            $etablissement = $reglage->getEstablishment();
            if ($etablissement === null) {
                continue;
            }

            $seuil = $reglage->getCashAlertThresholdCents();
            if ($seuil === null) {
                continue;
            }

            $projection = $this->calculator->projeter($etablissement, $aujourdhui, $seuil, $reglage->getCashAlertHorizonDays());

            $alerteOuverte = $this->em->getRepository(TreasuryCashAlert::class)->findOneBy([
                'establishment' => $etablissement->getId(),
                'status' => CashAlertStatus::Open,
            ]);

            if ($projection->breachDate === null) {
                if ($alerteOuverte !== null) {
                    $this->em->wrapInTransaction(function () use ($alerteOuverte, $maintenant): void {
                        $alerteOuverte->setStatus(CashAlertStatus::Resolved)->setResolvedAt($maintenant);
                        $this->em->persist($alerteOuverte);
                        $this->em->flush();
                    });
                    ++$resolues;
                }

                continue;
            }

            if ($alerteOuverte === null) {
                $this->em->wrapInTransaction(function () use ($reglage, $etablissement, $seuil, $projection, $maintenant): void {
                    $alerte = (new TreasuryCashAlert())
                        ->setEstablishment($etablissement)
                        ->setStatus(CashAlertStatus::Open)
                        ->setThresholdCentsAtDetection($seuil)
                        ->setHorizonDaysAtDetection($reglage->getCashAlertHorizonDays())
                        ->setProjectedBreachDate($projection->breachDate)
                        ->setProjectedBalanceCents($projection->balanceCentsAtBreach ?? 0)
                        ->setCauseSource($projection->causeSource)
                        ->setCauseSourceId($projection->causeSourceId !== null ? Uuid::fromString($projection->causeSourceId) : null)
                        ->setCauseAmountCents($projection->causeAmountCents)
                        ->setLastCheckedAt($maintenant)
                        ->setLastNotifiedAt($maintenant);

                    $this->em->persist($alerte);
                    $this->em->flush();

                    $this->eventBus->publish($this->evenement($alerte));
                });
                ++$creees;
                ++$notifiees;

                continue;
            }

            // §0.5 du plan — la valeur stockée AVANT mise à jour reflète toujours la dernière date
            // connue (silencieuse ou notifiante) : comparer à cette valeur équivaut strictement à
            // comparer à « la date de la dernière notification » (§4.4 de la spec).
            $ancienneDate = $alerteOuverte->getProjectedBreachDate();
            $aggravation = $projection->breachDate < $ancienneDate;

            $this->em->wrapInTransaction(function () use ($alerteOuverte, $projection, $maintenant, $aggravation): void {
                $alerteOuverte
                    ->setProjectedBreachDate($projection->breachDate)
                    ->setProjectedBalanceCents($projection->balanceCentsAtBreach ?? 0)
                    ->setCauseSource($projection->causeSource)
                    ->setCauseSourceId($projection->causeSourceId !== null ? Uuid::fromString($projection->causeSourceId) : null)
                    ->setCauseAmountCents($projection->causeAmountCents)
                    ->setLastCheckedAt($maintenant);

                if ($aggravation) {
                    $alerteOuverte->setLastNotifiedAt($maintenant);
                }

                $this->em->persist($alerteOuverte);
                $this->em->flush();

                if ($aggravation) {
                    $this->eventBus->publish($this->evenement($alerteOuverte));
                }
            });

            if ($aggravation) {
                ++$notifiees;
            }
        }

        $io->success(sprintf(
            '%d établissement(s) examiné(s) : %d alerte(s) créée(s), %d notification(s) émise(s), %d alerte(s) résolue(s).',
            \count($reglages),
            $creees,
            $notifiees,
            $resolues,
        ));

        return Command::SUCCESS;
    }

    private function evenement(TreasuryCashAlert $alerte): DomainEvent
    {
        $etablissement = $alerte->getEstablishment();
        \assert($etablissement !== null);

        $breachDate = $alerte->getProjectedBreachDate();

        return new DomainEvent(
            'treasury.threshold_breached',
            new EventTenant($etablissement->getId()),
            new EventSubject('TreasuryCashAlert', (string) $alerte->getId()),
            [
                'threshold_cents' => $alerte->getThresholdCentsAtDetection(),
                'projected_breach_date' => $breachDate->format('Y-m-d'),
                'projected_balance_cents' => $alerte->getProjectedBalanceCents(),
                'horizon_days' => $alerte->getHorizonDaysAtDetection(),
                'cause_source' => $alerte->getCauseSource(),
                'cause_source_id' => $alerte->getCauseSourceId() !== null ? (string) $alerte->getCauseSourceId() : null,
                'cause_amount_cents' => $alerte->getCauseAmountCents(),
            ],
        );
    }
}
