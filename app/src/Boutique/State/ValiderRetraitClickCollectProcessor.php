<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\RetraitClickCollect;
use App\Boutique\Service\ClickCollectHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /boutique/retraits/{id}/valider (US-L8-13, RG-M3-18, CA-18). Corps :
 * { "codeRetrait": string, "identifiantSupportPhysique"?: string }. Le code de retrait présenté doit
 * correspondre à celui émis à la confirmation.
 *
 * @implements ProcessorInterface<RetraitClickCollect, RetraitClickCollect>
 */
final class ValiderRetraitClickCollectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ClickCollectHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RetraitClickCollect
    {
        \assert($data instanceof RetraitClickCollect);
        $corps = $this->lecteur->corps();
        $code = \is_string($corps['codeRetrait'] ?? null) ? $corps['codeRetrait'] : '';
        if (!hash_equals($data->getCodeRetrait(), $code)) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Code de retrait invalide.');
        }

        $operateur = $this->security->getUser();
        $identifiant = \is_string($corps['identifiantSupportPhysique'] ?? null) ? $corps['identifiantSupportPhysique'] : null;
        $this->handler->valider($data, $operateur instanceof Utilisateur ? $operateur : null, $identifiant);

        return $data;
    }
}
