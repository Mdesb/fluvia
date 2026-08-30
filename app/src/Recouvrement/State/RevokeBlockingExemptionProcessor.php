<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Recouvrement\Entity\BlockingExemption;
use App\Recouvrement\Service\BlockingExemptionHandler;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /recouvrement/exemptions/{id}/retirer — le client redevient bloquable.
 *
 * ⚠ RETIRER NE FERME PAS LA PORTE : ÇA LA RÉÉVALUE. Un client exempté qui n'avait aucun impayé en
 * cours ne doit pas se retrouver dehors parce qu'on a retiré son exemption — il n'y avait rien à
 * bloquer. À l'inverse, celui qui doit encore de l'argent doit se retrouver bloqué immédiatement,
 * et non au prochain incident. `BlockingExemptionHandler` porte cette réévaluation.
 *
 * ⚠ LA LIGNE N'EST PAS SUPPRIMÉE. `revokedAt` bascule. Effacer perdrait la réponse à « qui avait
 * exempté ce client, et pourquoi, avant qu'on ne le rebloque » — c'est-à-dire exactement ce que le
 * motif obligatoire cherche à garantir à la pose. Une décision se relit, y compris annulée.
 *
 * ⚠ RETIRER DEUX FOIS EST ACCEPTÉ EN SILENCE, ET C'EST VOULU. Le second appel ne trouve rien à
 * changer et réévalue une porte déjà juste. Refuser en erreur ferait échouer un geste dont le
 * résultat est déjà celui qu'on voulait — typiquement deux agents qui cliquent à la même minute.
 *
 * @implements ProcessorInterface<BlockingExemption, BlockingExemption>
 */
final class RevokeBlockingExemptionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly BlockingExemptionHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BlockingExemption
    {
        \assert($data instanceof BlockingExemption);

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Utilisateur non authentifié.');
        }

        return $this->handler->retirer($data, $utilisateur);
    }
}
