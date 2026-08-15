<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\Entity\Client;
use App\Crm\Service\RenouvellementMajoriteHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `crm:consentement:verifier-majorite` (RG-M4-10, US-L5-10, CA-19/CA-20, §3.2 plan-crm.md) :
 * sélectionne les clients majeurs (18 ans révolus) dont au moins un consentement `accorde` porte
 * `recueilliParRepresentant=true`, et déclenche `RenouvellementMajoriteHandler`.
 */
#[AsCommand(name: 'crm:consentement:verifier-majorite', description: 'Passe en `a_renouveler` les consentements portés par le représentant légal d\'un bénéficiaire devenu majeur (US-L5-10).')]
final class VerifierMajoriteCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RenouvellementMajoriteHandler $handler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Fenêtre glissante (7 jours, ⚠ HYPOTHÈSE de rattrapage faute d'ordonnanceur défini par le
        // socle, §3.2/§10.10 plan-crm.md) : sélectionne les clients dont le 18ᵉ anniversaire est
        // survenu récemment. `RenouvellementMajoriteHandler` détermine lui-même, canal par canal,
        // l'état courant à faire évoluer — l'exécution répétée reste donc sans effet dupliqué
        // (idempotence naturelle : un canal déjà `a_renouveler` ne l'est pas redemandé).
        $seuil = new \DateTimeImmutable('-18 years');
        $bas = $seuil->modify('-7 days');

        $qb = $this->em->createQueryBuilder();
        $qb->select('c')->from(Client::class, 'c')
            ->where('c.dateNaissance IS NOT NULL')
            ->andWhere('c.dateNaissance <= :seuil')
            ->andWhere('c.dateNaissance > :bas')
            ->setParameter('seuil', $seuil, 'date_immutable')
            ->setParameter('bas', $bas, 'date_immutable');

        /** @var list<Client> $clients */
        $clients = $qb->getQuery()->getResult();

        foreach ($clients as $client) {
            $this->handler->traiter($client);
        }
        $this->em->flush();

        $io->success(sprintf('%d client(s) devenu(s) majeur(s) traité(s).', \count($clients)));

        return Command::SUCCESS;
    }
}
