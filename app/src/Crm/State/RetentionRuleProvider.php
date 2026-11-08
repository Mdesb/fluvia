<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\RegleConservation;
use App\Crm\Security\CustomerGroupGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /rgpd/retention-rule (#344) : la durée de conservation des fiches clients de la collectivité
 * courante (catégorie `identite`).
 *
 * `configured: false` quand aucune règle n'existe : c'est un état, pas une erreur — et c'est celui
 * où AUCUNE purge à échéance n'a lieu. L'écran le dit ; il ne l'invente pas en affichant un défaut.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class RetentionRuleProvider implements ProviderInterface
{
    public const CATEGORY = 'identite';

    public function __construct(
        private readonly CustomerGroupGuard $guard,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $groupe = $this->guard->activeGroup();
        $regle = $this->em->getRepository(RegleConservation::class)->findOneBy(['groupe' => $groupe, 'categorieDonnee' => self::CATEGORY]);

        return new JsonResponse(self::present($regle));
    }

    /** @return array<string, mixed> */
    public static function present(?RegleConservation $regle): array
    {
        return [
            'configured' => $regle !== null,
            'category' => self::CATEGORY,
            'months' => $regle?->getDureeMois(),
            'action' => $regle?->getActionEcheance()->value,
        ];
    }
}
