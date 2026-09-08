<?php

declare(strict_types=1);

namespace App\Musee\Command;

use App\Group\Entity\GroupBooking;
use App\Group\Entity\ParticipantGroup;
use App\Group\Enum\GroupBookingGrain;
use App\Group\Enum\GroupBookingStatus;
use App\Group\Enum\GroupPaymentStatus;
use App\Group\Enum\GroupType;
use App\Musee\Entity\DossierGroupeScolaire;
use App\Musee\Enum\StatutPaiementDossier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Absorption musée, Phase B (chantier coordonné, arbitrage Maxime « fidèle : étendre App\Group »).
 *
 * Migration de données **write-only, idempotente, rejouable** : pour chaque `DossierGroupeScolaire`,
 * crée son miroir `ParticipantGroup` + `GroupBooking` dans `App\Group`. **Aucun dossier n'est
 * supprimé ni modifié** ; les `Reservation` / `Gratuite` / `Vente` existantes ne sont pas touchées.
 * L'idempotence tient au marqueur de provenance `GroupBooking.sourceMuseeDossierId` : un dossier déjà
 * miroité est ignoré. Rejouable sans risque, réversible (supprimer les miroirs suffit).
 *
 * ⚠ Sens de dépendance : cette commande vit dans `Musee` (Musee → App\Group, jamais l'inverse).
 * Elle lit les dossiers par `findAll()`, qui NE passe PAS par `PerimetreMuseeExtension` (celle-ci ne
 * s'applique qu'aux collections API Platform) : la migration voit donc tous les établissements, et
 * chaque miroir est cloisonné à l'établissement de son dossier.
 *
 * Mapping du paiement (approché ; le grain visiteur et la sémantique argent sont portés au rebranch) :
 * en_option → (Option, Pending) · bon_commande_emis / mandat_emis → (Confirmed, PurchaseOrder) ·
 * paye → (Confirmed, Paid). Grain du miroir = `per_person` (le musée est au grain visiteur).
 */
#[AsCommand(
    name: 'musee:dossiers:migrer-vers-group',
    description: 'Migre les dossiers groupe/scolaire du musée en réservations de groupe App\\Group (write-only, idempotent).',
)]
final class MigrateGroupDossiersCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ne rien écrire : compter et rapporter seulement.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $dossiers = $this->em->getRepository(DossierGroupeScolaire::class)->findAll();
        $total = \count($dossiers);
        $migres = 0;
        $dejaMigres = 0;
        $ignores = 0;

        foreach ($dossiers as $dossier) {
            $etab = $dossier->getEtablissement();
            if ($etab === null) {
                // Dossier sans établissement : on ne l'invente pas, on le signale.
                ++$ignores;
                continue;
            }

            $existant = $this->em->getRepository(GroupBooking::class)
                ->findOneBy(['sourceMuseeDossierId' => $dossier->getId()]);
            if ($existant !== null) {
                ++$dejaMigres;
                continue;
            }

            if ($dryRun) {
                ++$migres;
                continue;
            }

            [$status, $paiement] = $this->mapperStatut($dossier->getStatutPaiement());

            $group = (new ParticipantGroup())
                ->setEtablissement($etab)
                ->setLabel($dossier->getEtablissementScolaire())
                ->setType(GroupType::School)
                ->setHeadcount($dossier->getEffectif());
            $this->em->persist($group);

            $booking = (new GroupBooking())
                ->setEtablissement($etab)
                ->setGroup($group)
                ->setCreneau($dossier->getCreneauEntree())
                ->setEffectif($dossier->getEffectif())
                ->setAccompagnateurs($dossier->getAccompagnateurs())
                ->setGrain(GroupBookingGrain::PerPerson)
                ->setStatus($status)
                ->setPaymentStatus($paiement)
                ->setOptionExpiresAt($dossier->getDateOption())
                ->setVenteRattachee($dossier->getVenteRattachee())
                ->setSourceMuseeDossierId($dossier->getId());
            $this->em->persist($booking);
            ++$migres;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s : %d dossier(s) lu(s) — %d miroité(s), %d déjà miroité(s), %d ignoré(s) (sans établissement).',
            $dryRun ? 'SIMULATION (rien écrit)' : 'Migration',
            $total,
            $migres,
            $dejaMigres,
            $ignores,
        ));

        return Command::SUCCESS;
    }

    /** @return array{0: GroupBookingStatus, 1: GroupPaymentStatus} */
    private function mapperStatut(StatutPaiementDossier $statut): array
    {
        return match ($statut) {
            StatutPaiementDossier::EnOption => [GroupBookingStatus::Option, GroupPaymentStatus::Pending],
            StatutPaiementDossier::BonCommandeEmis => [GroupBookingStatus::Confirmed, GroupPaymentStatus::PurchaseOrder],
            StatutPaiementDossier::MandatEmis => [GroupBookingStatus::Confirmed, GroupPaymentStatus::PurchaseOrder],
            StatutPaiementDossier::Paye => [GroupBookingStatus::Confirmed, GroupPaymentStatus::Paid],
        };
    }
}
