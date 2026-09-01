<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Défaire exactement ce qu'un lot a créé — et refuser dès qu'une ligne a servi.
 *
 * ── POURQUOI L'ANNULATION EXISTE ────────────────────────────────────────────────────────────────
 *
 * Elle rend la reprise **essayable**. Un exploitant qui sait qu'il peut revenir en arrière ose
 * lancer ; celui qui l'ignore repousse, et finit par ressaisir à la main — ce que toute cette
 * mécanique existe pour lui épargner.
 *
 * ── ET POURQUOI ELLE REFUSE ─────────────────────────────────────────────────────────────────────
 *
 * On ne défait pas ce qui a déjà servi. Supprimer un client sur lequel une vente a été faite ne
 * corrigerait pas un fichier : ça détruirait une écriture. Le refus nomme les lignes en cause, et
 * la voie reste ouverte — on corrige par un second import, pas en effaçant l'histoire.
 */
final class ImportReverter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportAnalyser $analyser,
    ) {
    }

    public function revert(ImportBatch $batch): ImportBatch
    {
        if (!$batch->getStatus()->canBeReverted()) {
            throw new ConflictHttpException(sprintf(
                'Ce lot est « %s » : seul un lot appliqué s\'annule.',
                $batch->getStatus()->value,
            ));
        }

        $handler = $this->analyser->handlerFor($batch->getType());
        if ($handler === null) {
            throw new ConflictHttpException(sprintf('Le type « %s » n\'est pas repris.', $batch->getType()->value));
        }

        $crees = $handler->createdBy($batch);

        // ── ON VÉRIFIE TOUT AVANT DE SUPPRIMER QUOI QUE CE SOIT ─────────────────────────────────
        //
        // Même raison que les deux temps de l'import : découvrir à la trois-centième ligne qu'un
        // client a servi, alors que deux cent quatre-vingt-dix-neuf sont déjà supprimés, laisserait
        // une reprise à moitié défaite — l'état le plus difficile à rattraper de tous.
        $employes = [];
        foreach ($crees as $objet) {
            if ($handler->hasBeenUsed($objet)) {
                $employes[] = $objet;
            }
        }

        if ($employes !== []) {
            throw new ConflictHttpException(sprintf(
                '%d des %d ligne(s) reprises par ce lot ont servi depuis : on ne les supprime pas. '
                . 'Corrigez par un second import plutôt qu\'en effaçant celui-ci.',
                count($employes),
                count($crees),
            ));
        }

        $this->em->wrapInTransaction(function () use ($crees, $batch): void {
            foreach ($crees as $objet) {
                $this->em->remove($objet);
            }

            $batch->setStatus(ImportStatus::Reverted)->setCreatedRows(0);
        });

        return $batch;
    }
}
