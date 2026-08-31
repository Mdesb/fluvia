<?php

declare(strict_types=1);

namespace App\Compta\Command;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Nf525\ScellementEcritureHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reprend l'instantané canonique des écritures scellées AVANT qu'on ne le conserve.
 *
 * ── LA CONDITION EST TOUT LE SUJET ──────────────────────────────────────────────────────────────
 *
 * On ne remplit l'instantané d'un document déjà scellé QUE si son empreinte se vérifie encore au
 * moment du passage. ⚠ Ce n'est pas une précaution : c'est ce qui sépare une reprise d'une
 * **fabrication de preuve**.
 *
 * Si l'instantané recalculé aujourd'hui redonne EXACTEMENT l'empreinte scellée hier, il est démontré
 * que c'est celui d'origine — une empreinte sha256 ne se retrouve pas par hasard. La coïncidence est
 * la preuve.
 *
 * Sinon, on ne sait pas si un référentiel a bougé ou si la donnée a été touchée. Écrire dans ce cas
 * fabriquerait la preuve qu'on prétend conserver, et c'est précisément le geste qu'un contrôle
 * reprocherait. Le document reste alors sans instantané, et la vérification le déclare
 * **non vérifiable** au lieu d'accuser son détenteur.
 *
 * Arbitré par Maxime le 31/08, sur trois issues possibles.
 *
 * ── CONSTAT PAR DÉFAUT, ÉCRITURE SUR DEMANDE ────────────────────────────────────────────────────
 *
 * Même pli que `crm:reprendre-regions-geographiques`. Une commande de reprise qui écrit dès qu'on
 * l'appelle est une commande qu'on n'ose plus lancer pour regarder — et qu'on finit par lancer à
 * l'aveugle le jour où il le faut vraiment.
 *
 * ── LA DÉCISION N'EST PAS ICI ───────────────────────────────────────────────────────────────────
 *
 * `ScellementEcritureHandler::reprendreInstantane()` porte la règle, parce que c'est là que vivent la
 * canonicalisation et le calcul d'empreinte. Cette commande ne fait que parcourir et compter : une
 * règle recopiée diverge au premier correctif, et celle-ci ne doit jamais diverger.
 */
#[AsCommand(
    name: 'compta:nf525:reprendre-instantanes',
    description: "Conserve l'instantané des écritures scellées avant, si leur empreinte se vérifie encore.",
)]
final class SealSnapshotBackfillCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementEcritureHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ecrire', null, InputOption::VALUE_NONE, 'Applique. Sans cette option : constat seulement.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = (bool) $input->getOption('ecrire');

        /** @var list<EcritureComptable> $sealed */
        $sealed = $this->em->getRepository(EcritureComptable::class)->createQueryBuilder('f')
            ->andWhere("f.empreinte <> ''")
            ->andWhere('f.payloadCanonique IS NULL')
            ->orderBy('f.numeroSequence', 'ASC')
            ->getQuery()
            ->getResult();

        if ($sealed === []) {
            $io->success('Aucune écriture scellée sans instantané : rien à reprendre.');

            return Command::SUCCESS;
        }

        $recovered = [];
        $unverifiable = [];

        foreach ($sealed as $entry) {
            if ($this->handler->reprendreInstantane($entry)) {
                $recovered[] = $this->designer($entry);

                continue;
            }

            // ⚠ ON N'ÉCRIT RIEN ICI, ET SURTOUT PAS UN INSTANTANÉ « APPROCHANT ». Un document dont
            // l'empreinte ne se vérifie plus ne prouve rien sur son contenu d'origine.
            $unverifiable[] = $this->designer($entry);
        }

        $io->section(sprintf('%d écriture(s) scellée(s) sans instantané', \count($sealed)));

        if ($recovered !== []) {
            $io->writeln(sprintf('  ✓ %d reprise(s) — empreinte vérifiée, instantané prouvé d\'origine :', \count($recovered)));
            foreach ($recovered as $numero) {
                $io->writeln('      · ' . $numero);
            }
        }

        if ($unverifiable !== []) {
            $io->writeln('');
            $io->writeln(sprintf('  ⚠ %d NON VÉRIFIABLE(S) — laissée(s) sans instantané :', \count($unverifiable)));
            foreach ($unverifiable as $numero) {
                $io->writeln('      · ' . $numero);
            }
            $io->writeln('');
            $io->writeln("    Leur empreinte ne se reproduit plus. On ne peut pas dire si un référentiel");
            $io->writeln("    a changé ou si la donnée a été touchée — donc on n'écrit rien plutôt que");
            $io->writeln('    de fabriquer la preuve qu\'on prétend conserver.');
        }

        if (!$write) {
            $io->writeln('');
            $io->note('Constat seulement. Relance avec --ecrire pour appliquer.');

            // ⚠ Les entités ont été modifiées en mémoire par `reprendreInstantane()` : sans ce
            // détachement, un flush ultérieur du même processus les écrirait quand même. Un mode
            // « constat » qui écrit par un chemin détourné est pire qu'une absence de mode constat.
            $this->em->clear();

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d instantané(s) repris, %d laissé(s) non vérifiable(s).', \count($recovered), \count($unverifiable)));

        return Command::SUCCESS;
    }

    /**
     * Une écriture comptable n'a pas de numéro de document — on la désigne par sa place dans la
     * chaîne et son libellé, qui sont ce qu'un comptable cherche dans un journal.
     */
    private function designer(EcritureComptable $entry): string
    {
        return sprintf('séq. %d — %s', $entry->getNumeroSequence(), $entry->getLibelle() ?? '(sans libellé)');
    }

}
