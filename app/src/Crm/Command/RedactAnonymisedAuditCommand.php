<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Audit\Service\ClientAuditRedactor;
use App\Crm\Entity\Client;
use App\Crm\Enum\StatutClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * `crm:rgpd:redact-audit` (#363) : caviarde l'audit des fiches DÉJÀ anonymisées.
 *
 * L'anonymisation d'une fiche caviarde désormais son audit (`ClientAuditRedactor`, dans la même
 * transaction). Cette commande rattrape l'arriéré : les fiches anonymisées avant #363, dont les entrées
 * de création et de modification gardent le nom, le courriel, le téléphone. Tâche d'exploitation, lancée
 * une fois en préprod puis une fois en production, sous supervision ; elle n'est pas planifiée.
 *
 *  - `--dry-run` compte sans rien écrire. Il respecte `--limit` : il annonce ce qu'un passage réel ferait.
 *  - `--limit` borne le nombre de fiches RÉELLEMENT caviardées par passage (500 par défaut), pas celui des
 *    fiches examinées : sinon un second passage retomberait sur les mêmes premières fiches, déjà propres.
 *  - Idempotente : une fiche propre n'est ni réécrite ni tracée ; un second passage rend 0 fiche, 0 entrée.
 *  - Parcours par identifiant croissant, avec reprise après le dernier lu (pas de OFFSET), 100 identifiants
 *    par page ; un flush et un `clear()` PAR FICHE : une fiche et sa trace partent dans la même transaction.
 *  - Aucun filtre par groupe : la tâche travaille fiche par fiche et n'expose que des compteurs.
 */
#[AsCommand(name: 'crm:rgpd:redact-audit', description: 'Caviarde l\'audit des fiches clients déjà anonymisées (#363).')]
final class RedactAnonymisedAuditCommand extends Command
{
    private const PAGE_SIZE = 100;
    private const AUTHOR = 'tache:crm:rgpd:redact-audit';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientAuditRedactor $redactor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter les fiches et les entrées concernées sans rien écrire.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de fiches caviardées par passage.', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            $io->error('--limit doit être un entier positif.');

            return Command::INVALID;
        }

        $customerCount = 0;
        $entryCount = 0;
        $last = null;
        $limitReached = false;
        while (!$limitReached) {
            $ids = $this->pageOfIds($last);
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                $last = $id;
                if ($customerCount >= $limit) {
                    $limitReached = true;

                    break;
                }
                $n = $this->redactOne($id, $dryRun);
                if ($n > 0) {
                    ++$customerCount;
                    $entryCount += $n;
                }
            }
        }
        // Le `while` s'arrête sur la limite avant d'avoir vu la fiche suivante : il peut en rester.
        $limitReached = $limitReached || $customerCount >= $limit;

        $io->text(sprintf('%d fiche(s), %d entrée(s)', $customerCount, $entryCount));
        if ($limitReached) {
            $io->warning('limite atteinte : il peut en rester (relancer, ou augmenter --limit).');
        }
        $io->success($dryRun ? 'Simulation : rien n\'a été écrit.' : sprintf('%d fiche(s) caviardée(s).', $customerCount));

        return Command::SUCCESS;
    }

    /**
     * Une fiche, une transaction : le caviardage, sa trace, puis flush et `clear()` — l'unité de travail
     * ne garde jamais plus d'une fiche (et ses entrées modifiées) en mémoire.
     */
    private function redactOne(Uuid $id, bool $dryRun): int
    {
        $client = $this->em->find(Client::class, $id);
        if (!$client instanceof Client) {
            return 0;
        }
        $n = $dryRun ? $this->redactor->countRedactable((string) $id) : $this->redactor->redact($client, self::AUTHOR);
        if (!$dryRun) {
            $this->em->flush();
        }
        $this->em->clear();

        return $n;
    }

    /** @return list<Uuid> la page suivante des identifiants de fiches anonymisées, par ordre croissant */
    private function pageOfIds(?Uuid $after): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(Client::class, 'c')
            ->where('c.statut = :statut')
            ->setParameter('statut', StatutClient::Anonymise)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(self::PAGE_SIZE);
        if ($after !== null) {
            $qb->andWhere('c.id > :after')->setParameter('after', $after, 'uuid');
        }

        return array_map(static fn (mixed $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString((string) $id), array_column($qb->getQuery()->getScalarResult(), 'id'));
    }
}
