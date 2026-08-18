<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\AvoirFactureHandler;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /factures/{id}/avoir (US-FACT-05, RG-FACT-05, CA-6). Aucun corps requis (avoir total, §7 point
 * 7 du plan — simplification assumée).
 *
 * @implements ProcessorInterface<Facture, Facture>
 */
final class GenererAvoirFactureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AvoirFactureHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Facture
    {
        \assert($data instanceof Facture);
        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        return $this->handler->genererAvoir($data, $auteur);
    }
}
