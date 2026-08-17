<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Stock\Entity\LigneInventaire;
use App\Stock\Service\InventaireRegularisationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `POST /stock/lignes-inventaire/{id}/regulariser` (RG-STOCK-12, §2.3, CA-13) : régularisation directe
 * si non significatif (`stock.inventorier`), sinon requiert `stock.valider_ecart` (Responsable).
 *
 * @implements ProcessorInterface<mixed, LigneInventaire>
 */
final class RegulariserLigneInventaireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventaireRegularisationHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LigneInventaire
    {
        \assert($data instanceof LigneInventaire);

        $utilisateur = $this->security->getUser();
        $validateur = $utilisateur instanceof Utilisateur ? $utilisateur : null;
        $autoriseValidationEcart = $this->security->isGranted('PERM', 'stock.valider_ecart');

        $this->handler->regulariser($data, $validateur, $autoriseValidationEcart);
        $this->em->flush();

        return $data;
    }
}
