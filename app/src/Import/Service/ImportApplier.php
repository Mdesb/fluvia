<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Crm\Entity\Client;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Second temps : appliquer un lot **en une transaction**, ou ne rien faire.
 *
 * ── LA TRANSACTION N'EST PAS UNE PRÉCAUTION, C'EST LA DÉCISION ──────────────────────────────────
 *
 * « Tout refuser » ne veut rien dire si l'écriture peut s'arrêter au milieu. Le premier temps a
 * jugé le fichier entier ; celui-ci garantit que ce jugement se traduit en base sans état
 * intermédiaire — quatre mille clients, ou aucun.
 *
 * ── ET IL RELIT AVANT D'ÉCRIRE ──────────────────────────────────────────────────────────────────
 *
 * Le lot a pu être validé il y a une heure, et un autre import — ou une saisie à la main — avoir
 * entré une des références depuis. Réappliquer sans relire créerait le doublon que toute cette
 * mécanique existe pour empêcher. On repasse donc par l'analyse : si le verdict a changé, on refuse.
 */
final class ImportApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CsvReader $reader,
        private readonly ImportAnalyser $analyser,
    ) {
    }

    public function apply(ImportBatch $batch): ImportBatch
    {
        if (!$batch->getStatus()->canBeApplied()) {
            throw new ConflictHttpException(sprintf(
                'Ce lot est « %s » : seul un lot validé s\'applique.',
                $batch->getStatus()->value,
            ));
        }

        $establishment = $batch->getEstablishment();
        if ($establishment === null) {
            throw new ConflictHttpException('Ce lot n\'est rattaché à aucun établissement.');
        }

        // Relecture : le monde a pu bouger depuis la validation.
        $this->analyser->analyse($batch, $establishment);
        if ($batch->getStatus() !== ImportStatus::Validated) {
            $this->em->flush();

            throw new ConflictHttpException(
                'Le fichier n\'est plus applicable en l\'état : des données ont changé depuis sa validation. '
                . 'Les lignes en cause sont dans « errors ».'
            );
        }

        $handler = $this->analyser->handlerFor($batch->getType());
        if ($handler === null) {
            throw new ConflictHttpException(sprintf('Le type « %s » n\'est pas repris.', $batch->getType()->value));
        }

        $lu = $this->reader->read($batch->getContent());
        $connues = $this->referencesConnues($batch);

        $this->em->wrapInTransaction(function () use ($batch, $lu, $handler, $establishment, $connues): void {
            $crees = 0;
            foreach ($lu['rows'] as $ligne) {
                $reference = $ligne['data']['externalRef'] ?? '';
                if ($reference === '' || isset($connues[$reference])) {
                    continue;
                }

                $handler->create($ligne['data'], $establishment, $batch);
                ++$crees;
            }

            $batch
                ->setStatus(ImportStatus::Applied)
                ->setAppliedAt(new \DateTimeImmutable())
                ->setCreatedRows($crees);
        });

        return $batch;
    }

    /** @return array<string, true> */
    private function referencesConnues(ImportBatch $batch): array
    {
        $groupe = $batch->getEstablishment()?->getRegion()?->getGroupe();
        if ($groupe === null) {
            return [];
        }

        /** @var list<array{externalRef: string}> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('c.externalRef')
            ->from(Client::class, 'c')
            ->andWhere('IDENTITY(c.groupe) = :groupe')
            ->andWhere('c.externalRef IS NOT NULL')
            ->setParameter('groupe', $groupe->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();

        $connues = [];
        foreach ($lignes as $l) {
            $connues[$l['externalRef']] = true;
        }

        return $connues;
    }
}
