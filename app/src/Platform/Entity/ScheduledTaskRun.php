<?php

declare(strict_types=1);

namespace App\Platform\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * La trace d'exécution d'une tâche périodique.
 *
 * **Elle n'est pas là pour faire joli.** La règle tirée de la nuit du 24 au 25/08 est qu'un mécanisme
 * doit pouvoir **prouver** qu'il s'est exécuté — sept mécanismes de ce dépôt existaient sans tourner,
 * et aucun ne le disait. Sans cette table, un ordonnanceur silencieux est indiscernable d'un
 * ordonnanceur absent, ce qui est exactement le défaut qu'on répare.
 *
 * Volontairement **hors API** : c'est de l'exploitation, pas une ressource métier. Elle se lit par
 * `platform:scheduler:status`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'platform_scheduled_task_run')]
class ScheduledTaskRun
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120, unique: true)]
    private string $command;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastStartedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFinishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastExitCode = null;

    /** Le message d'échec, tronqué : on veut savoir quoi chercher, pas rejouer la trace. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $runCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $failureCount = 0;

    public function __construct(string $command)
    {
        $this->id = Uuid::v7();
        $this->command = $command;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getLastStartedAt(): ?\DateTimeImmutable
    {
        return $this->lastStartedAt;
    }

    public function getLastFinishedAt(): ?\DateTimeImmutable
    {
        return $this->lastFinishedAt;
    }

    public function getLastExitCode(): ?int
    {
        return $this->lastExitCode;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getRunCount(): int
    {
        return $this->runCount;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function markStarted(\DateTimeImmutable $at): void
    {
        $this->lastStartedAt = $at;
        ++$this->runCount;
    }

    public function markFinished(\DateTimeImmutable $at, int $exitCode, ?string $error = null): void
    {
        $this->lastFinishedAt = $at;
        $this->lastExitCode = $exitCode;
        $this->lastError = $error !== null ? mb_substr($error, 0, 2000) : null;

        if ($exitCode !== 0) {
            ++$this->failureCount;
        }
    }

    /**
     * Une tâche jamais terminée est due. Une tâche dont le dernier ACHÈVEMENT date de plus de son
     * intervalle l'est aussi.
     *
     * On compte depuis l'achèvement et non depuis le démarrage : sinon une tâche longue serait relancée
     * pendant qu'elle tourne encore, ce qui est le meilleur moyen de transformer une purge en course.
     */
    public function isDue(\DateTimeImmutable $now, int $everyMinutes): bool
    {
        if ($this->lastFinishedAt === null) {
            return true;
        }

        return $this->lastFinishedAt->modify(sprintf('+%d minutes', $everyMinutes)) <= $now;
    }
}
