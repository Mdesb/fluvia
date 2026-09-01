<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\Reengagement;
use App\Sport\Service\ReengagementHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/abonnements/{id}/reengager (US-SPORT-04, CA-4). Corps :
 *   { "dureeEngagementMois"?: int, "iban": string, "titulaireMandat": string,
 *     "dateReengagement"?: "AAAA-MM-JJ" }
 * **Nouveau mandat SEPA obligatoire** (décision actée), même si un ancien mandat non révoqué existait.
 *
 * @implements ProcessorInterface<AbonnementFitness, Reengagement>
 */
final class ReengagerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ReengagementHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reengagement
    {
        \assert($data instanceof AbonnementFitness);

        $corps = $this->lecteur->corps();
        $iban = \is_string($corps['iban'] ?? null) ? $corps['iban'] : '';
        $titulaire = \is_string($corps['titulaireMandat'] ?? null) ? $corps['titulaireMandat'] : '';
        if (trim($iban) === '' || trim($titulaire) === '') {
            throw new UnprocessableEntityHttpException('« iban » et « titulaireMandat » sont requis pour le nouveau mandat SEPA.');
        }

        // ⚠ REFUSÉ, PAS IGNORÉ — même raison que sur la souscription : un appelant qui continue
        //    d'envoyer un montant croirait fixer le prix, et le tarif s'appliquerait à sa place.
        if (isset($corps['montantCentimes'])) {
            throw new UnprocessableEntityHttpException(
                '« montantCentimes » n\'est plus accepté : le prix est résolu depuis la grille '
                . 'tarifaire du produit qui porte cette formule. Retirez ce champ.',
            );
        }

        $dureeEngagementMois = isset($corps['dureeEngagementMois']) ? (int) $corps['dureeEngagementMois'] : 12;
        $dateReengagement = isset($corps['dateReengagement']) && \is_string($corps['dateReengagement'])
            ? new \DateTimeImmutable($corps['dateReengagement'])
            : new \DateTimeImmutable('today');

        return $this->handler->reengager($data, $dateReengagement, $dureeEngagementMois, $iban, $titulaire);
    }
}
