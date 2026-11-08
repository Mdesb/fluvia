<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Audit\Service\JournalAudit;
use App\Crm\Entity\RegleConservation;
use App\Crm\Enum\ActionConservation;
use App\Crm\Enum\PrivacyRequestOrigin;
use App\Crm\Service\EffacementRgpdHandler;
use App\Crm\Service\PurgeCandidateFinder;
use App\Organisation\Entity\Groupe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `crm:rgpd:appliquer-conservation` (RG-M4-08, #207, #344) : pour chaque `RegleConservation` de
 * catégorie `identite`, anonymise les fiches clients DE CE GROUPE inactives depuis plus de
 * `dureeMois`.
 *
 * ── CE QUI A CHANGÉ AU #344, ET POURQUOI ───────────────────────────────────────────────────────
 *
 * La première version (#207) prétendait réutiliser `EffacementRgpdHandler` et recopiait
 * l'anonymisation à la main ; elle comptait depuis la CRÉATION de la fiche ; elle n'avait ni borne
 * ni simulation. Elle n'a jamais tourné (D109 : premier passage supervisé exigé).
 *
 *  - L'anonymisation passe désormais par `EffacementRgpdHandler::anonymiseWithoutRequest()` — la
 *    même que pour une demande, avec une demande réalisée d'origine `retention` par fiche.
 *  - Le critère est l'INACTIVITÉ (`PurgeCandidateFinder`) : la CNIL fait courir la durée depuis la
 *    fin de la relation commerciale (délibération n° 2021-131 du 23/09/2021), pas depuis l'inscription.
 *  - Les fiches protégées (abonnement vivant, solde de porte-monnaie positif) ne sont jamais touchées.
 *  - `--dry-run` compte sans écrire ; `--limit` borne le nombre de fiches par groupe et par passage
 *    (500 par défaut) : un premier passage sur un arriéré de dix ans se fait en plusieurs nuits, et
 *    chacune peut être relue.
 *
 * Une règle `purge` (suppression physique) est ignorée et signalée : une fiche client porte des
 * ventes scellées (NF525) et n'est jamais supprimée physiquement (§1 plan-crm.md).
 *
 * Cloisonnement : chaque règle ne s'applique qu'à SON groupe — la requête du finder le pose en
 * premier. Une collectivité sans règle n'a aucune fiche purgée.
 */
#[AsCommand(name: 'crm:rgpd:appliquer-conservation', description: 'Applique les règles de conservation RGPD échues (RG-M4-08).')]
final class AppliquerConservationCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurgeCandidateFinder $finder,
        private readonly EffacementRgpdHandler $handler,
        private readonly JournalAudit $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter les fiches concernées sans rien anonymiser.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de fiches anonymisées par groupe et par passage.', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');
        $limite = (int) $input->getOption('limit');
        if ($limite < 1) {
            $io->error('--limit doit être un entier positif.');

            return Command::INVALID;
        }

        /** @var list<RegleConservation> $regles */
        $regles = $this->em->getRepository(RegleConservation::class)->findBy(['categorieDonnee' => 'identite']);

        $traites = 0;
        foreach ($regles as $regle) {
            $groupe = $regle->getGroupe();
            if (!$groupe instanceof Groupe) {
                continue;
            }
            if ($regle->getActionEcheance() !== ActionConservation::Anonymisation) {
                $io->warning(sprintf('Groupe « %s » : action « %s » non appliquée (seule l\'anonymisation existe).', $groupe->getNom(), $regle->getActionEcheance()->value));

                continue;
            }
            $seuil = (new \DateTimeImmutable())->modify(sprintf('-%d months', $regle->getDureeMois()));

            if ($simulation) {
                $io->text(sprintf(
                    'Groupe « %s » : %d fiche(s) inactive(s) depuis %d mois, dont %d anonymisable(s) (%d au plus par passage).',
                    $groupe->getNom(),
                    $this->finder->countInactive($groupe, $seuil, null),
                    $regle->getDureeMois(),
                    $this->finder->countEligible($groupe, $seuil, null),
                    $limite,
                ));

                continue;
            }

            foreach ($this->finder->candidates($groupe, $seuil, null, $limite) as $client) {
                $this->handler->anonymiseWithoutRequest($client, PrivacyRequestOrigin::Retention, null);
                $this->journal->enregistrer('crm.rgpd.conservation-echue', 'Client', (string) $client->getId(), null, 'tache:crm:rgpd:appliquer-conservation');
                ++$traites;
            }
            $this->em->flush();
        }

        if ($simulation) {
            $io->success('Simulation : rien n\'a été anonymisé.');
        } else {
            $io->success(sprintf('%d fiche(s) client anonymisée(s) par conservation échue.', $traites));
        }

        return Command::SUCCESS;
    }
}
