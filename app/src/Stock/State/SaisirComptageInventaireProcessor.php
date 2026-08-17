<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\LigneInventaire;
use App\Stock\Service\InventaireRegularisationHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `PATCH /stock/lignes-inventaire/{id}` (RG-STOCK-12, CA-13) : saisie du comptage, calcule
 * `ecart`/`significatif`. Refusé si l'inventaire n'est plus en cours (append-only après clôture).
 *
 * @implements ProcessorInterface<mixed, LigneInventaire>
 */
final class SaisirComptageInventaireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly InventaireRegularisationHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LigneInventaire
    {
        \assert($data instanceof LigneInventaire);

        $inventaire = $data->getInventaire();
        if ($inventaire !== null && $inventaire->getStatut()->value === 'cloture') {
            throw new ConflictHttpException('Inventaire clôturé : lignes non modifiables (append-only).');
        }

        $corps = $this->lecteur->corps();
        $quantite = \is_scalar($corps['quantiteComptee'] ?? null) ? (string) $corps['quantiteComptee'] : null;
        if ($quantite === null) {
            throw new UnprocessableEntityHttpException('Champ « quantiteComptee » obligatoire.');
        }

        $this->handler->saisirComptage($data, $quantite);
        $this->em->flush();

        return $data;
    }
}
