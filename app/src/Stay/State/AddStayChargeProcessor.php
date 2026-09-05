<?php

declare(strict_types=1);

namespace App\Stay\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stay\Entity\Stay;
use App\Stay\Service\StayChargeRecorder;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Porte une ligne au compte du séjour **à la main** (ACT-3). Corps attendu :
 *   { "label": "Degat des eaux", "amount": "40.00", "occurredAt": "2026-08-26T09:00:00"? }
 *
 * **La saisie manuelle n'est pas une roue de secours.** C'est le mode nominal d'un hôtelier qui ajoute
 * une casse, un geste commercial ou un extra non catalogué — et c'est aussi ce qui rend le module
 * utilisable aujourd'hui, alors que `sale.completed` et `access.recorded` ne sont émis par personne.
 *
 * **Chaque saisie porte son propre identifiant de source.** Deux lignes « Bar » de 9,00 € le même jour
 * sont deux consommations réelles, pas un doublon : leur donner le même sujet les ferait fusionner
 * par la clé d'idempotence, et le client paierait une tournée sur deux.
 *
 * @implements ProcessorInterface<mixed, Stay>
 */
final class AddStayChargeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly StayFromRequest $sejours,
        private readonly StayChargeRecorder $recorder,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Stay
    {
        $corps = $this->lecteur->corps();
        $sejour = $this->sejours->resolve($uriVariables);

        if (!$sejour->acceptsCharges()) {
            // ⚠ `stay_settled` ET NON `stay_closed` DEPUIS LE 05/09 : un séjour clos accepte encore
            // des lignes — c'est ainsi qu'on encaisse après le départ. Seul un séjour soldé refuse.
            // Garder l'ancien code aurait fait chercher un défaut de clôture là où il n'y en a pas.
            throw new ConflictHttpException('stay.error.stay_settled');
        }

        $libelle = $corps['label'] ?? null;
        if (!is_string($libelle) || '' === trim($libelle)) {
            throw new UnprocessableEntityHttpException('stay.error.label_required');
        }

        $montant = $corps['amount'] ?? null;
        if (!is_string($montant) || 1 !== preg_match('/^-?\d+(\.\d{1,2})?$/', $montant)) {
            // Refus d'un flottant JSON : `40.1` deserialise en float perdrait le centime avant meme
            // d'arriver ici. Le montant se transmet en chaine, comme il est stocke.
            throw new UnprocessableEntityHttpException('stay.error.amount_invalid');
        }

        $this->recorder->record(
            $sejour,
            trim($libelle),
            number_format((float) $montant, 2, '.', ''),
            isset($corps['occurredAt']) && is_string($corps['occurredAt'])
                ? new \DateTimeImmutable($corps['occurredAt'])
                : new \DateTimeImmutable('now'),
            'manual',
            'manual.entry',
            Uuid::v7()->toRfc4122(),
        );

        return $sejour;
    }
}
