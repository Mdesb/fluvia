<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\Entity\DemandeRGPD;
use App\Crm\Enum\StatutDemandeRgpd;
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

/**
 * `crm:rgpd:alerter-delai` — signale les demandes RGPD qui ont dépassé le délai légal d'un mois.
 *
 * ── POURQUOI CETTE TÂCHE EXISTE ─────────────────────────────────────────────────────────────────
 *
 * L'écran « Données personnelles » a quitté le menu quotidien le 03/09 (R27) : il n'est plus sous
 * les yeux tous les matins. Or une demande d'effacement porte un délai d'UN MOIS, opposable, et
 * aucune des sept tâches planifiées ne le regardait. Le badge du menu répond à qui regarde ; cette
 * tâche répond à qui ne regarde plus. Arbitrage de Maxime : « on met une notification et un badge ».
 *
 * ── ⚠ CHAQUE DEMANDE N'EST SIGNALÉE QU'UNE FOIS, ET C'EST LE CŒUR DE CETTE COMMANDE ─────────────
 *
 * Sans `deadlineAlertedAt`, une tâche nocturne re-notifierait la même demande CHAQUE NUIT jusqu'à
 * son traitement. Une cloche qui répète s'apprend à ne plus se lire — c'est très exactement le
 * défaut qu'on cherche à corriger, réintroduit par le remède.
 *
 * ── ⚠ ET ELLE EST SÛRE AU PREMIER PASSAGE, CE QUI N'EST PAS ACQUIS D'AVANCE ─────────────────────
 *
 * `ordonnanceur.sh` appelle ses tâches avec `--only`, ce qui CONTOURNE la garde `safeOnFirstRun`.
 * Une tâche qui rattraperait tout l'historique d'un coup n'a donc rien à faire dans sa liste. Ici :
 * la préproduction porte 2 demandes, dont 1 en attente et ZÉRO au-delà du mois — mesuré le 03/09
 * avant d'inscrire la tâche. Le premier passage ne signalera rien.
 *
 * ⚠ Sur un parc où beaucoup de demandes seraient déjà en retard, le premier passage en signalerait
 * autant. C'est voulu : une demande hors délai EST un incident, et le silence coûte plus cher que
 * le bruit. `--plafond` existe pour qui veut découvrir l'ampleur avant d'ouvrir les vannes.
 */
#[AsCommand(
    name: 'crm:rgpd:alerter-delai',
    description: 'Signale une fois chaque demande RGPD en attente qui a dépassé le délai légal d’un mois.',
)]
final class AlertPrivacyDeadlineCommand extends Command
{
    /** Le délai légal de réponse : un mois à compter de la réception (RGPD, art. 12-3). */
    private const DELAI_LEGAL = '-1 month';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'plafond',
            null,
            \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,
            'N’en signaler que ce nombre au maximum, pour découvrir l’ampleur sans inonder la cloche.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();
        $limite = $maintenant->modify(self::DELAI_LEGAL);

        $plafond = $input->getOption('plafond');
        $plafond = $plafond === null ? null : max(1, (int) $plafond);

        $qb = $this->em->createQueryBuilder()
            ->select('d')
            ->from(DemandeRGPD::class, 'd')
            ->where('d.statut IN (:en_attente)')
            ->andWhere('d.dateDemande < :limite')
            // ⚠ C'EST CETTE LIGNE QUI REND LA COMMANDE IDEMPOTENTE. Sans elle, chaque nuit
            //    reprendrait les mêmes demandes.
            ->andWhere('d.deadlineAlertedAt IS NULL')
            ->setParameter('en_attente', [StatutDemandeRgpd::Recue, StatutDemandeRgpd::EnCours])
            ->setParameter('limite', $limite)
            ->orderBy('d.dateDemande', 'ASC');

        if ($plafond !== null) {
            $qb->setMaxResults($plafond);
        }

        /** @var list<DemandeRGPD> $enRetard */
        $enRetard = $qb->getQuery()->getResult();

        if ($enRetard === []) {
            $io->writeln('Aucune demande RGPD au-delà du délai légal qui n’ait déjà été signalée.');

            return Command::SUCCESS;
        }

        $signalees = 0;
        foreach ($enRetard as $demande) {
            $etablissement = $demande->getClient()?->getEtablissementCreation();
            if ($etablissement === null) {
                // ⚠ ON NE DEVINE PAS DE DESTINATAIRE. Une notification sans établissement ne sait
                //    pas à qui elle s'adresse ; on laisse la demande NON signalée plutôt que de la
                //    marquer traitée sans que personne l'ait vue.
                $io->warning(sprintf(
                    'Demande %s : aucun établissement rattaché, elle reste à signaler.',
                    $demande->getId(),
                ));
                continue;
            }

            $jours = (int) $demande->getDateDemande()->diff($maintenant)->days;

            $this->bus->publish(new DomainEvent(
                'privacy_request.deadline_breached',
                new EventTenant($etablissement->getId()),
                new EventSubject('PrivacyRequest', (string) $demande->getId()),
                [
                    'privacy_request' => (string) $demande->getId(),
                    'request_type' => $demande->getType()->value,
                    'days_elapsed' => $jours,
                ],
                // ⚠ `actor` VAUT `null`, ET C'EST LA VÉRITÉ : personne n'a déclenché ce
                //    signalement. C'est le temps qui a passé. Le catalogue le dit —
                //    « null = le système (tâche planifiée) ».
                null,
                $maintenant,
            ));

            $demande->setDeadlineAlertedAt($maintenant);
            ++$signalees;
        }

        $this->em->flush();

        $io->success(sprintf('%d demande(s) RGPD hors délai signalée(s).', $signalees));

        return Command::SUCCESS;
    }
}
