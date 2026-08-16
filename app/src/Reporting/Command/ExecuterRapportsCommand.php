<?php

declare(strict_types=1);

namespace App\Reporting\Command;

use App\Reporting\Entity\DestinataireRapport;
use App\Reporting\Entity\Export;
use App\Reporting\Entity\RapportPlanifie;
use App\Reporting\Entity\TableauDeBord;
use App\Reporting\Enum\EtatRapportPlanifie;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Enum\PeriodiciteRapport;
use App\Reporting\Enum\StatutExport;
use App\Reporting\Exception\GenerationExportNonSupporteeException;
use App\Reporting\Notification\RapportPlanifieMailer;
use App\Reporting\Service\MesureLookupService;
use App\Reporting\Service\ResolveurGenerateurExport;
use App\Reporting\Service\StockageExportInterface;
use App\Reporting\ValueObject\Periode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * `reporting:executer-rapports` (§2.8 plan-reporting.md, RG-M7-06, CA-7/CA-8) : sélectionne les
 * `RapportPlanifie` actifs échus, génère et envoie un `Export` INDIVIDUALISÉ par destinataire
 * (jamais le périmètre du rapport « au sens large », CA-8). Un rapport `suspendu` n'est jamais
 * sélectionné (CA-7). Ordonnancement cron externe (§8, même arbitrage que
 * `securite:delegations:expirer`).
 */
#[AsCommand(
    name: 'reporting:executer-rapports',
    description: 'Génère et envoie les RapportPlanifie actifs échus, un Export par destinataire (périmètre individualisé).',
)]
final class ExecuterRapportsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurGenerateurExport $resolveurGenerateur,
        private readonly StockageExportInterface $stockage,
        private readonly RapportPlanifieMailer $mailer,
        private readonly MesureLookupService $lookup,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();

        /** @var list<RapportPlanifie> $rapports */
        $rapports = $this->em->createQueryBuilder()
            ->select('r')
            ->from(RapportPlanifie::class, 'r')
            ->where('r.etat = :actif')
            ->andWhere('r.prochainEnvoi IS NULL OR r.prochainEnvoi <= :maintenant')
            ->setParameter('actif', EtatRapportPlanifie::Actif)
            ->setParameter('maintenant', $maintenant)
            ->getQuery()
            ->getResult();

        $nbGeneres = 0;
        $nbEnvoyes = 0;
        $nbEchecs = 0;

        foreach ($rapports as $rapport) {
            foreach ($rapport->getDestinataires() as $destinataire) {
                \assert($destinataire instanceof DestinataireRapport);
                $export = $this->genererEtEnvoyer($rapport, $destinataire);
                $this->em->persist($export);

                match ($export->getStatut()) {
                    StatutExport::Envoye => ++$nbEnvoyes,
                    StatutExport::Genere => ++$nbGeneres,
                    StatutExport::Echec => ++$nbEchecs,
                };
            }

            $rapport->setDernierEnvoi($maintenant);
            $rapport->setProchainEnvoi($this->calculerProchainEnvoi($rapport, $maintenant));
        }

        $this->em->flush();

        $io->success(sprintf(
            '%d rapport(s) traité(s) : %d export(s) envoyé(s), %d généré(s) sans envoi, %d échec(s).',
            \count($rapports),
            $nbEnvoyes,
            $nbGeneres,
            $nbEchecs,
        ));

        return Command::SUCCESS;
    }

    private function genererEtEnvoyer(RapportPlanifie $rapport, DestinataireRapport $destinataire): Export
    {
        $export = new Export();
        $export->setRapportPlanifie($rapport);
        $export->setDestinataireEmail($destinataire->getEmail());
        $export->setFormat($rapport->getFormat());
        match ($destinataire->getNiveau()) {
            NiveauEntite::Etablissement => $export->definirRattachementEtablissement($destinataire->getEtablissement() ?? throw new \LogicException('Établissement destinataire manquant.')),
            NiveauEntite::Region => $export->definirRattachementRegion($destinataire->getRegion() ?? throw new \LogicException('Région destinataire manquante.')),
            NiveauEntite::Groupe => $export->definirRattachementGroupe($destinataire->getGroupe() ?? throw new \LogicException('Groupe destinataire manquant.')),
        };

        try {
            $tdb = $rapport->getTableauDeBord() ?? throw new \LogicException('Tableau de bord manquant.');
            [$entetes, $lignes] = $this->construirePayload($tdb, $destinataire);
            $genere = $this->resolveurGenerateur->pour($rapport->getFormat())->generer($entetes, $lignes);
            $reference = $this->stockage->stocker($genere->contenu, $genere->extension);
            $export->setCheminStockage($reference);
            $export->setStatut(StatutExport::Genere);

            $this->mailer->envoyer($export, $destinataire->getEmail(), $rapport->getNom(), $genere->contenu, 'rapport.' . $genere->extension);
            $export->setStatut(StatutExport::Envoye);
            $export->setEnvoyeLe(new \DateTimeImmutable());
        } catch (GenerationExportNonSupporteeException $e) {
            $export->setStatut(StatutExport::Echec);
            $export->setMessageErreur($e->getMessage());
        } catch (\Throwable $e) {
            $export->setStatut(StatutExport::Echec);
            $export->setMessageErreur('Échec de génération/envoi : ' . $e->getMessage());
        }

        return $export;
    }

    /** @return array{0: list<string>, 1: list<array<string, string>>} */
    private function construirePayload(TableauDeBord $tableauDeBord, DestinataireRapport $destinataire): array
    {
        $periode = Periode::jour();
        $niveau = $destinataire->getNiveau();
        $entiteId = $this->entiteIdDestinataire($destinataire);

        $entetes = ['indicateur', 'valeur', 'statutCompletude'];
        $lignes = [];
        foreach ($tableauDeBord->getIndicateurs() as $indicateur) {
            $mesure = $entiteId !== null ? $this->lookup->trouver($indicateur->getCode(), $niveau, $entiteId, $periode) : null;
            $lignes[] = [
                'indicateur' => $indicateur->getCode(),
                'valeur' => $mesure?->getValeur() ?? '',
                'statutCompletude' => $mesure?->getStatutCompletude()->value ?? '',
            ];
        }

        return [$entetes, $lignes];
    }

    private function entiteIdDestinataire(DestinataireRapport $destinataire): ?Uuid
    {
        return match ($destinataire->getNiveau()) {
            NiveauEntite::Etablissement => $destinataire->getEtablissement()?->getId(),
            NiveauEntite::Region => $destinataire->getRegion()?->getId(),
            NiveauEntite::Groupe => $destinataire->getGroupe()?->getId(),
        };
    }

    private function calculerProchainEnvoi(RapportPlanifie $rapport, \DateTimeImmutable $depuis): \DateTimeImmutable
    {
        return match ($rapport->getPeriodicite()) {
            PeriodiciteRapport::Quotidienne => $depuis->modify('+1 day'),
            PeriodiciteRapport::Hebdomadaire => $depuis->modify('+1 week'),
            PeriodiciteRapport::Mensuelle => $depuis->modify('+1 month'),
        };
    }
}
