<?php

declare(strict_types=1);

namespace App\Autorisation\Command;

use App\Audit\Service\JournalAudit;
use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Enum\StatutEscalade;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `autorisation:escalades:expirer` (RG-AUTZ-07, CA-9) : même patron exact que
 * `securite:delegations:expirer` (`ExpirerDelegationsCommand`) — sélectionne les `DemandeEscalade`
 * `en_attente` dont `dateExpiration` est dépassée, passe `statut = Expiree`, journalise
 * `escalade.expiree` pour chacune, `flush()` unique en fin de commande. Idempotente, sans effet de
 * bord si aucune ligne. À planifier via cron externe.
 */
#[AsCommand(
    name: 'autorisation:escalades:expirer',
    description: "Passe au statut « expirée » les demandes d'escalade en attente dont la date d'expiration est dépassée.",
)]
final class ExpirerEscaladesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAudit $journal,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();

        $qb = $this->em->createQueryBuilder()
            ->select('d')
            ->from(DemandeEscalade::class, 'd')
            ->where('d.statut = :en_attente')
            ->andWhere('d.dateExpiration < :maintenant')
            ->setParameter('en_attente', StatutEscalade::EnAttente)
            ->setParameter('maintenant', $maintenant);

        /** @var list<DemandeEscalade> $demandes */
        $demandes = $qb->getQuery()->getResult();

        foreach ($demandes as $demande) {
            \assert($demande instanceof DemandeEscalade);
            $demande->setStatut(StatutEscalade::Expiree);

            $entree = $this->journal->enregistrer('escalade.expiree', 'DemandeEscalade', (string) $demande->getId(), $demande->getEtablissement()?->getId());
            $entree->setValeurApres(['statut' => 'expiree']);
        }

        $this->em->flush();

        $io->success(sprintf("%d demande(s) d'escalade expirée(s).", \count($demandes)));

        return Command::SUCCESS;
    }
}
