<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\CatalogueFournisseur;
use App\Stock\Entity\CommandeAchat;
use App\Stock\Enum\StatutCommandeAchat;
use App\Stock\Service\GenerateurNumeroAchat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * `POST /stock/commandes-achat/{id}/envoyer` (RG-STOCK-04, CA-4) : brouillon → envoyée. Numérote la
 * commande et dérive `dateLivraisonPrevue` du plus long `CatalogueFournisseur.delaiLivraisonJours`
 * parmi les articles commandés (à défaut, aucune date). Aucun mouvement de stock (CA-4).
 *
 * @implements ProcessorInterface<mixed, CommandeAchat>
 */
final class EnvoyerCommandeAchatProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenerateurNumeroAchat $generateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommandeAchat
    {
        \assert($data instanceof CommandeAchat);
        if ($data->getStatut() !== StatutCommandeAchat::Brouillon) {
            throw new ConflictHttpException('Seule une commande en brouillon peut être envoyée.');
        }

        if ($data->getNumero() === '') {
            $data->setNumero($this->generateur->genererPourCommande());
        }

        $delaiMax = 0;
        foreach ($data->getLignes() as $ligne) {
            $catalogue = $this->em->getRepository(CatalogueFournisseur::class)->findOneBy([
                'fournisseur' => $data->getFournisseur()?->getId(),
                'articleStock' => $ligne->getArticleStock()?->getId(),
            ]);
            if ($catalogue instanceof CatalogueFournisseur) {
                $delaiMax = max($delaiMax, $catalogue->getDelaiLivraisonJours());
            }
        }
        if ($delaiMax > 0 && $data->getDateCommande() !== null) {
            $data->setDateLivraisonPrevue($data->getDateCommande()->modify(sprintf('+%d days', $delaiMax)));
        }

        $data->setStatut(StatutCommandeAchat::Envoyee);
        $this->em->flush();

        return $data;
    }
}
