<?php

declare(strict_types=1);

namespace App\PublicApi\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * La référence OPAQUE d'un support, telle qu'une application partenaire la voit (spec API partenaire v1, §3.2).
 *
 * ⚠ **JAMAIS LE CODE DU QR OU DE LA CARTE.** Une clé `access:read` qui fuit ne doit pas permettre de
 * cloner les badges : le code réel n'est servi qu'au terminal lié à son site. Le partenaire reçoit
 * `HMAC(clé serveur, application ‖ support)` — stable pour lui d'un appel à l'autre (il peut relier un
 * droit à ses passages), et sans rapport avec le code.
 *
 * ⚠ **L'APPLICATION EST DANS LE HMAC.** Deux applications ne voient pas la même référence pour le même
 * support : deux partenaires ne peuvent pas recouper leurs données par cette référence.
 *
 * Clé dédiée `PUBLIC_API_SUPPORT_REF_KEY`, générée par installation (`infra/deploy-preprod.sh`, exigée
 * par `compose.preprod.yaml`). ⚠ `app/.env` en porte un MARQUEUR versionné, donc public : il est
 * refusé ici hors environnement de test, à la première signature — sans ce refus, une installation
 * qui l'aurait oublié signerait avec une valeur que tout le monde connaît. La changer change toutes
 * les références : un partenaire devrait tout resynchroniser.
 */
final class SupportReferenceSigner
{
    private const MARKER_PREFIX = 'A_GENERER';

    public function __construct(
        #[Autowire(env: 'PUBLIC_API_SUPPORT_REF_KEY')] private readonly string $signingKey,
        #[Autowire(param: 'kernel.environment')] string $environment,
    ) {
        if ('test' !== $environment && ('' === $signingKey || str_starts_with($signingKey, self::MARKER_PREFIX))) {
            throw new \LogicException('PUBLIC_API_SUPPORT_REF_KEY n’est pas définie : app/.env n’en porte qu’un marqueur public. Générez une clé par installation (infra/deploy-preprod.sh).');
        }
    }

    public function reference(Uuid $application, Uuid $support): string
    {
        return 'sup_'.hash_hmac('sha256', $application->toRfc4122().':'.$support->toRfc4122(), $this->signingKey);
    }
}
