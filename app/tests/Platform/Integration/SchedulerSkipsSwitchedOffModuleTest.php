<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\DataFixtures\SocleFixtures;
use App\Platform\Entity\ScheduledTaskRun;
use App\Tests\DdlHorsMapping;
use App\Tests\Platform\ModuleSwitch;
use App\Tests\SchemaDuHarnais;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Recentrage, CA-3 — une tâche planifiée d'un module éteint ne tourne pas, et le dit.
 *
 * La tâche d'épreuve est une tâche réelle du catalogue appartenant à Padel, module masqué. Éteint
 * partout : l'ordonnanceur la saute, l'écrit dans sa sortie, et n'en garde aucune trace d'exécution.
 * Allumé pour un seul établissement : la même tâche tourne — c'est le témoin, sans lequel « rien
 * n'a tourné » pourrait venir du verrou du premier passage ou d'une fenêtre non due.
 *
 * Lancé pour de vrai, pas en `--dry-run` : un passage à blanc ne prouve pas qu'une exécution est
 * empêchée. `--supervise` lève le verrou du premier passage (D109), qui ferait sinon taire la tâche
 * pour une autre raison que celle qu'on mesure.
 */
final class SchedulerSkipsSwitchedOffModuleTest extends KernelTestCase
{
    use ModuleSwitch;

    private const TASK = 'padel:parties:maintenir-a-3';

    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        SchemaDuHarnais::reinitialiser($em);
        DdlHorsMapping::appliquer($em);
        static::getContainer()->get(SocleFixtures::class)->load($em);

        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
    }

    public function testATaskOfAModuleSwitchedOffEverywhereDoesNotRun(): void
    {
        $this->switchModule('padel', false);

        $output = $this->run_();

        self::assertStringContainsString(self::TASK, $output);
        self::assertStringContainsString('éteint', $output, 'le saut doit se lire dans le journal');
        self::assertNull($this->lastFinishedAt(), 'la tâche ne doit pas avoir tourné');
    }

    public function testTheSameTaskRunsWhenTheModuleIsOnSomewhere(): void
    {
        $this->switchModule('padel', false);
        $this->switchModule('padel', true, SocleFixtures::ETAB_A_NOM);

        $output = $this->run_();

        self::assertStringNotContainsString('éteint', $output);
        self::assertNotNull($this->lastFinishedAt(), 'allumé pour A, la tâche tourne');
    }

    /**
     * Personnel est du CŒUR (décision de Maxime du 20/10, à la relecture de la PR 2) : ses tâches
     * tournent sans aucune ligne d'activation. La sortie du personnel révoque des badges ; la sauter
     * laisserait un ancien salarié entrer.
     */
    public function testPersonnelTasksRunWithoutAnyActivationRow(): void
    {
        foreach (['personnel:traiter-echeances-sortie', 'personnel:recalculer-fenetres-badges'] as $task) {
            $output = $this->run_($task);

            self::assertStringNotContainsString('éteint', $output, $task);
            self::assertNotNull($this->lastFinishedAt($task), $task . ' doit avoir tourné');
        }
    }

    /**
     * Une tâche sautée se lit dans `--status` comme sautée, jamais comme « JAMAIS » : les deux
     * appellent des gestes opposés (allumer le module, ou réparer l'ordonnanceur).
     */
    public function testStatusSaysASkippedTaskIsSkippedAndWhy(): void
    {
        $this->switchModule('padel', false);
        $this->run_();

        $output = new BufferedOutput();
        $this->application->find('platform:scheduler:run')->run(new ArrayInput(['--status' => true]), $output);
        $line = array_values(array_filter(explode("\n", $output->fetch()), static fn (string $l): bool => str_contains($l, self::TASK)));

        self::assertCount(1, $line, 'la tâche figure une fois au tableau');
        self::assertStringContainsString('sautée : module padel éteint', $line[0]);
        self::assertStringNotContainsString('JAMAIS', $line[0]);
    }

    /**
     * L'alarme « AUCUNE tâche n'a jamais tourné » doit tenir malgré les tâches sautées. Depuis D133 il
     * y en a toujours (les modules masqués sont éteints) : les compter comme des lignes du tableau
     * sans les compter comme « jamais » désarmait l'alarme. Base neuve, Padel éteint, rien n'a
     * tourné : `--status` doit échouer.
     */
    public function testStatusStillAlarmsWhenNothingEverRanDespiteSkippedTasks(): void
    {
        $this->switchModule('padel', false);

        $output = new BufferedOutput();
        $code = $this->application->find('platform:scheduler:run')->run(new ArrayInput(['--status' => true]), $output);
        $text = $output->fetch();

        self::assertStringContainsString('sautée : module padel éteint', $text, 'montage : au moins une tâche sautée');
        self::assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $code, 'aucune tâche non sautée n\'a jamais tourné : alarme');
        self::assertStringContainsString('AUCUNE', $text);
    }

    private function run_(string $task = self::TASK): string
    {
        $output = new BufferedOutput();
        $this->application->find('platform:scheduler:run')->run(
            new ArrayInput(['--only' => $task, '--supervise' => true]),
            $output,
        );

        return $output->fetch();
    }

    private function lastFinishedAt(string $task = self::TASK): ?\DateTimeImmutable
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $trace = $em->getRepository(ScheduledTaskRun::class)->findOneBy(['command' => $task]);

        return $trace instanceof ScheduledTaskRun ? $trace->getLastFinishedAt() : null;
    }
}
