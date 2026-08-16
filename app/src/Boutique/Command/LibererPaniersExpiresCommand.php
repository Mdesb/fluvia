<?php

declare(strict_types=1);

namespace App\Boutique\Command;

use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Notification\RelancePanierExpireMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `boutique:liberer-paniers-expires` (RG-M3-03/16, CA-3) : fait expirer les paniers dont
 * `dateExpiration` est dépassée. **Aucune** `Reservation` n'est jamais créée pour un panier expiré
 * (§4.3 spec) — le compteur temporaire (`LignePanierEnLigne.expirationA`) cesse simplement d'être
 * comptabilisé dans `DisponibiliteAffichageHandler`. Relance e-mail si contact connu, idempotent
 * (`relanceEnvoyee`).
 */
#[AsCommand(name: 'boutique:liberer-paniers-expires', description: 'Expire les paniers en ligne dépassant leur délai (RG-M3-03/16).')]
final class LibererPaniersExpiresCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RelancePanierExpireMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $paniers = $this->em->getRepository(PanierEnLigne::class)->createQueryBuilder('p')
            ->andWhere('p.statut = :ouvert')
            ->andWhere('p.dateExpiration <= :maintenant')
            ->setParameter('ouvert', StatutPanier::Ouvert->value)
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        $nbExpires = 0;
        $nbRelances = 0;
        foreach ($paniers as $panier) {
            \assert($panier instanceof PanierEnLigne);
            $panier->setStatut(StatutPanier::Expire);
            if (!$panier->isRelanceEnvoyee() && $this->mailer->envoyerSiContactConnu($panier)) {
                $panier->setRelanceEnvoyee(true);
                ++$nbRelances;
            }
            ++$nbExpires;
        }
        $this->em->flush();

        $io->success(sprintf('%d panier(s) expiré(s), %d relance(s) envoyée(s).', $nbExpires, $nbRelances));

        return Command::SUCCESS;
    }
}
