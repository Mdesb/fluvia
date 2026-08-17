<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\CommandeAchat;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Entity\ReceptionAchat;
use App\Stock\Enum\OrigineLotStock;
use App\Stock\Enum\StatutCommandeAchat;
use App\Stock\Enum\StatutReceptionAchat;
use App\Stock\Enum\TypeMouvementStock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Validation d'une réception d'achat (US-STOCK-04, RG-STOCK-05, CA-5, §3.1 du plan) : crée une
 * nouvelle couche de coût (`LotStock`) + un mouvement `entree_achat` par ligne, met à jour
 * `Stock.disponibilité` M1 (DBAL, même transaction) et recalcule le statut de la commande liée
 * (`partiellement_reçue`/`reçue`). Ne flush jamais — l'appelant (Processor) porte la transaction.
 */
final class ReceptionAchatValidationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisponibiliteStockHandler $disponibilite,
    ) {
    }

    public function valider(ReceptionAchat $reception): void
    {
        if ($reception->getStatut() !== StatutReceptionAchat::Brouillon) {
            throw new ConflictHttpException('Cette réception est déjà validée.');
        }

        foreach ($reception->getLignes() as $ligne) {
            $article = $ligne->getArticleStock();
            if ($article === null) {
                continue;
            }

            $lot = new LotStock();
            $lot->setArticleStock($article)
                ->setEtablissement($article->getEtablissement())
                ->setDateEntree(new \DateTimeImmutable())
                ->setQuantiteInitiale($ligne->getQuantiteRecue())
                ->setQuantiteRestante($ligne->getQuantiteRecue())
                ->setCoutUnitaireHT($ligne->getPrixAchatUnitaireHT())
                ->setOrigine(OrigineLotStock::Reception)
                ->setReferenceOrigineType('ReceptionAchat')
                ->setReferenceOrigineId($reception->getId());
            $this->em->persist($lot);
            $ligne->setLotCree($lot);

            $mouvement = new MouvementStock();
            $mouvement->setArticleStock($article)
                ->setEtablissement($article->getEtablissement())
                ->setType(TypeMouvementStock::EntreeAchat)
                ->setQuantite($ligne->getQuantiteRecue())
                ->setReferenceType('ReceptionAchat')
                ->setReferenceId($reception->getId());
            $this->em->persist($mouvement);

            $this->disponibilite->incrementer($article, $ligne->getQuantiteRecue());

            $ligneCommande = $ligne->getLigneCommandeAchat();
            if ($ligneCommande !== null) {
                $ligneCommande->setQuantiteRecue(ArithmetiqueDecimale::additionner($ligneCommande->getQuantiteRecue(), $ligne->getQuantiteRecue(), 3));
            }
        }

        $reception->setStatut(StatutReceptionAchat::Validee);

        $commande = $reception->getCommandeAchat();
        if ($commande !== null) {
            $this->recalculerStatutCommande($commande);
        }
    }

    private function recalculerStatutCommande(CommandeAchat $commande): void
    {
        $totalCommande = 0;
        $totalRecu = 0;
        foreach ($commande->getLignes() as $ligne) {
            $totalCommande += ArithmetiqueDecimale::versEntier($ligne->getQuantiteCommandee(), 3);
            $totalRecu += ArithmetiqueDecimale::versEntier($ligne->getQuantiteRecue(), 3);
        }

        if ($totalRecu <= 0) {
            return;
        }

        $commande->setStatut($totalRecu >= $totalCommande ? StatutCommandeAchat::Recue : StatutCommandeAchat::PartiellementRecue);
    }
}
