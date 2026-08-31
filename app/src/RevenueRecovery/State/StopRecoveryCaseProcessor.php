<?php

declare(strict_types=1);

namespace App\RevenueRecovery\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\RevenueRecovery\Entity\RecoveryCase;
use App\RevenueRecovery\Service\RecoveryEngine;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /revenue-recovery/cases/{id}/stop` (RG-RR-05, plan-revenue-recovery.md §2). Corps :
 * `{ "reason": string }` — motif obligatoire, délégué à `RecoveryEngine::stopManually()` (422 si vide,
 * même exigence que `RG-SOCLE-07`/`App\Recouvrement\Service\ResolutionImpayeHandler::forcerReouverture()`).
 *
 * `{id}` résolu via le provider d'item standard (`read: true` sur la ressource, §2 du plan), donc déjà
 * filtré par `RevenueRecoveryScopeExtension` — un `RecoveryCase` hors périmètre renvoie 404 avant même
 * d'atteindre ce processor (échec fermé, RG-RR-07).
 *
 * @implements ProcessorInterface<mixed, RecoveryCase>
 */
final class StopRecoveryCaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly RecoveryEngine $engine,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RecoveryCase
    {
        // `read: true` + POST : quand `RevenueRecoveryScopeExtension` filtre un dossier hors périmètre,
        // le provider d'item renvoie `null` et API Platform, en sémantique POST, appelle quand même ce
        // processor avec `$data === null` (au lieu de 404). On rétablit l'échec fermé RG-RR-07 : 404,
        // jamais un 500 d'assertion, jamais une fuite d'existence hors périmètre.
        if (!$data instanceof RecoveryCase) {
            throw new NotFoundHttpException('revenue_recovery.error.case_not_found');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('revenue_recovery.error.not_authenticated');
        }

        $corps = $this->lecteur->corps();
        $reason = \is_string($corps['reason'] ?? null) ? $corps['reason'] : '';

        return $this->engine->stopManually($data, $utilisateur, $reason);
    }
}
