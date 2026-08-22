<?php

declare(strict_types=1);

namespace App\Dms\Command;

use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Enum\DocumentStatus;
use App\Dms\Enum\RetentionStatus;
use App\Dms\Service\RetentionStatusCalculator;
use App\Dms\Storage\Storage;
use App\Organisation\Entity\Etablissement;
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
 * `dms:purge-expired-documents` (RG-DMS-15, plan §7) — patron
 * `App\Crm\Command\AppliquerConservationCommand` (planifiée, cron quotidien). Sélectionne les
 * `Document` où `status = deleted` **ET** `deletedAt <= now() - 30 jours` (grâce, arbitrage D18 pt.6)
 * **ET** `RetentionStatusCalculator::statusFor(retainUntil) !== Active` (`None` ou `Expired`
 * purgeables, seul `Active` bloque, RG-DMS-12).
 *
 * **CA-11** : `status = deleted` est une condition **obligatoire**, jamais optionnelle — un document
 * `expired` mais jamais explicitement supprimé n'est jamais sélectionné.
 *
 * Pour chaque version non déjà purgée : `Storage::delete()` retire le contenu physique, `purgedAt` est
 * marqué (plan §14 pt.10) — les métadonnées `DocumentVersion` restent en base (RG-DMS-14).
 */
#[AsCommand(
    name: 'dms:purge-expired-documents',
    description: 'Purge physiquement le contenu des versions de documents supprimés depuis plus de 30 jours et dont la rétention n\'est pas active (RG-DMS-15).',
)]
final class PurgeDocumentsCommand extends Command
{
    private const DELAI_GRACE_JOURS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Storage $storage,
        private readonly RetentionStatusCalculator $retentionStatusCalculator,
        private readonly EventBus $eventBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seuil = (new \DateTimeImmutable())->modify(sprintf('-%d days', self::DELAI_GRACE_JOURS));

        $qb = $this->em->createQueryBuilder();
        $qb->select('d')->from(Document::class, 'd')
            ->where('d.status = :statut')
            ->andWhere('d.deletedAt IS NOT NULL')
            ->andWhere('d.deletedAt <= :seuil')
            ->setParameter('statut', DocumentStatus::Deleted->value)
            ->setParameter('seuil', $seuil);

        /** @var list<Document> $documents */
        $documents = $qb->getQuery()->getResult();

        $purges = 0;
        foreach ($documents as $document) {
            if ($this->retentionStatusCalculator->statusFor($document->getRetainUntil()) === RetentionStatus::Active) {
                continue; // seul le statut Active bloque (RG-DMS-12/15) — None/Expired sont purgeables.
            }

            $etablissement = $document->getEstablishment();
            \assert($etablissement instanceof Etablissement);

            /** @var list<DocumentVersion> $versions */
            $versions = $this->em->getRepository(DocumentVersion::class)->findBy(['document' => $document]);

            $auMoinsUnePurge = false;
            foreach ($versions as $version) {
                if ($version->getPurgedAt() !== null) {
                    continue; // déjà purgée à une exécution précédente — idempotent.
                }
                $this->storage->delete($version->getStorageKey());
                $version->markPurged(new \DateTimeImmutable());
                $auMoinsUnePurge = true;
            }
            $this->em->flush();

            if ($auMoinsUnePurge) {
                $this->eventBus->publish(new DomainEvent(
                    'document.purged',
                    new EventTenant($etablissement->getId()),
                    new EventSubject('Document', (string) $document->getId()),
                    [
                        'category' => $document->getCategory()->value,
                        'retainUntilWas' => $document->getRetainUntil()?->format('Y-m-d'),
                    ],
                ));
                ++$purges;
            }
        }

        $io->success(sprintf('%d document(s) purgé(s) physiquement.', $purges));

        return Command::SUCCESS;
    }
}
