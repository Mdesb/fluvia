<?php

declare(strict_types=1);

namespace App\Acces\Command;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\TypeDroitAcces;
use App\Audit\Service\JournalAudit;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reprise de #297 : l'échéance des droits de carte multi-entrées émis avant ce correctif passe à
 * 23:59:59 le jour butoir, à l'heure de l'établissement (décision de Maxime du 08/10/2026).
 *
 * Avant #297, la date butoir (colonne `date`, rendue à 00:00 UTC) devenait l'échéance telle quelle :
 * tourniquet et borne hors ligne refusaient la carte dès 01:00 ou 02:00 à Paris le jour butoir. #297
 * corrige l'émission et la recharge ; un droit déjà émis garde l'ancienne échéance jusqu'à sa
 * prochaine recharge, et pour toujours si la carte conserve son échéance (mode « keep »).
 *
 * ⚠ ELLE NE TOUCHE QU'À UNE ÉCHÉANCE ÉGALE, À LA SECONDE, À LA DATE BUTOIR DE SA CARTE À 00:00 UTC :
 * la trace exacte de l'ancienne règle. Recalée, l'échéance finit à :59 et ne répond plus au critère :
 * relancer ne change rien.
 *
 * ⚠ CONSTAT PAR DÉFAUT (`--dry-run`), ÉCRITURE SUR `--executer`. L'écriture passe par l'entité, sous
 * verrou, critère relu : `AccessProjectionVersionListener` fait avancer la version des supports
 * appairés (les bornes en delta reçoivent la nouvelle `validiteFin`), et une recharge concurrente
 * n'est pas écrasée. Une entrée d'audit par droit.
 *
 * Limite : une carte à durée ET date butoir dont la durée s'achevait le jour butoir reçoit minuit
 * local, pas la fin de sa durée (l'instant d'émission n'est pas conservé), soit moins d'un jour de plus.
 */
#[AsCommand(
    name: 'acces:recaler-echeances-cartes',
    description: 'Recale à 23:59:59, heure de l’établissement, l’échéance des droits de carte émis avant #297.',
)]
final class RealignCardDeadlinesCommand extends Command
{
    public const AUDIT_ACTION = 'acces.droit.echeance_carte_recalee';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste ce qui changerait, sans rien écrire (défaut).')
            ->addOption('executer', null, InputOption::VALUE_NONE, 'Écrit les nouvelles échéances, une entrée d’audit par droit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $executer = (bool) $input->getOption('executer');
        if ($executer && $input->getOption('dry-run')) {
            $io->error('--dry-run et --executer s’excluent.');

            return Command::INVALID;
        }

        $lignes = [];
        foreach ($this->em->getRepository(DroitAcces::class)->findBy(['sourceType' => TypeDroitAcces::CarteQuota]) as $droit) {
            $cible = $this->target($droit);
            if ($cible !== null && $executer) {
                $cible = $this->realign($droit); // null : une recharge l'a réécrite entre-temps.
            }
            if ($cible === null) {
                continue;
            }
            [$avant, $apres, $butoir, $carte] = $cible;
            $fuseau = new \DateTimeZone($droit->getEtablissement()?->getFuseauHoraire() ?? 'Europe/Paris');
            $lignes[] = [
                (string) $droit->getId(),
                sprintf('%s (butoir %s)', $carte, $butoir),
                sprintf('%s (%s)', $droit->getEtablissement()?->getNom(), $fuseau->getName()),
                $avant->setTimezone($fuseau)->format(\DATE_ATOM),
                $apres->setTimezone($fuseau)->format(\DATE_ATOM),
            ];
        }

        $io->title('Échéances des cartes multi-entrées émises avant #297');
        if ($lignes !== []) {
            $io->table(['Droit', 'Carte', 'Établissement', 'Fin actuelle', 'Nouvelle fin'], $lignes);
        }
        if ($executer) {
            $io->success(sprintf('%d droit(s) recalé(s), une entrée d’audit chacun.', \count($lignes)));
        } else {
            $io->writeln(sprintf('  %d droit(s) à recaler.', \count($lignes)));
            $io->note('Constat seulement, rien n’est écrit. Relance avec --executer pour appliquer.');
        }

        return Command::SUCCESS;
    }

    /**
     * Sous verrou, le critère relu : une recharge concurrente a pu réécrire l'échéance entre-temps.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string, 3: string}|null comme `target()`
     */
    private function realign(DroitAcces $droit): ?array
    {
        return $this->em->wrapInTransaction(function () use ($droit): ?array {
            $this->em->refresh($droit, LockMode::PESSIMISTIC_WRITE);
            $cible = $this->target($droit);
            if ($cible !== null) {
                [$avant, $apres, $butoir] = $cible;
                $droit->setFenetreFin($apres);
                $this->journal->enregistrer(self::AUDIT_ACTION, DroitAcces::class, (string) $droit->getId(), $droit->getEtablissement()?->getId())
                    ->setValeurAvant(['fenetreFin' => $avant->format(\DATE_ATOM)])
                    ->setValeurApres(['fenetreFin' => $apres->format(\DATE_ATOM), 'dateButoir' => $butoir]);
            }

            return $cible;
        });
    }

    /**
     * Fin actuelle, nouvelle fin, date butoir et carte, si le droit porte l'échéance de l'ancienne
     * règle : la date butoir de sa carte à 00:00:00 UTC, telle que la colonne la stocke (PHP en UTC).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string, 3: string}|null
     */
    private function target(DroitAcces $droit): ?array
    {
        $fin = $droit->getFenetreFin();
        $produit = $droit->getProduitRef() !== null ? $this->em->find(Produit::class, $droit->getProduitRef()) : null;
        $butoir = $produit?->getCarte()?->getDateButoir()?->format('Y-m-d');
        if ($droit->getSourceType() !== TypeDroitAcces::CarteQuota || $fin === null || $butoir === null
            || $fin->format('Y-m-d H:i:s') !== $butoir . ' 00:00:00') {
            return null;
        }
        $apres = Etablissement::instantLocal($droit->getEtablissement(), $butoir . ' 23:59:59');

        return [$fin, $apres, $butoir, $produit?->getLibelleRecherche() ?? (string) $droit->getProduitRef()];
    }
}
