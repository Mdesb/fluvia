<?php

declare(strict_types=1);

namespace App\Stay\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Stay\ApiResource\StayFolioView;
use App\Stay\Service\StayBalance;
use App\Stay\Service\StayFolio;

/**
 * GET /stays/{id}/folio — la note du séjour, calculée à la demande.
 *
 * **Le périmètre est vérifié avant toute lecture de ligne**, par `StayFromRequest`, qui résout le
 * séjour et le confronte dans le même geste. Un provider qui chargerait d'abord les lignes pour ne
 * vérifier qu'ensuite aurait déjà fait le travail d'un attaquant.
 *
 * **Une seule requête pour les lignes, et le solde en dérive.** Demander séparément le total à la base
 * ouvrirait la porte à ce que la note affichée et son total ne proviennent pas du même instant — sur
 * un compte qui se remplit pendant qu'on le consulte, ce n'est pas théorique.
 *
 * @implements ProviderInterface<StayFolioView>
 */
final class StayFolioProvider implements ProviderInterface
{
    public function __construct(
        private readonly StayFromRequest $stays,
        private readonly StayFolio $folio,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StayFolioView
    {
        $stay = $this->stays->resolve($uriVariables);
        $lignes = $this->folio->linesOf($stay);

        $vue = new StayFolioView();
        $vue->id = (string) $stay->getId();
        $vue->reference = $stay->getReference();
        $vue->status = $stay->getStatus()->value;
        $vue->balance = StayBalance::fromAmounts(array_column($lignes, 'amount'))->total();
        $vue->lineCount = \count($lignes);
        $vue->lines = array_map(
            static fn (array $ligne): array => [
                'label' => $ligne['label'],
                'amount' => $ligne['amount'],
                'occurredAt' => $ligne['occurredAt']->format(\DateTimeInterface::ATOM),
                'sourceModule' => $ligne['sourceModule'],
            ],
            $lignes,
        );

        return $vue;
    }
}
