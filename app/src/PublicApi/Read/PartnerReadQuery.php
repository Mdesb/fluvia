<?php

declare(strict_types=1);

namespace App\PublicApi\Read;

use App\PublicApi\Enum\ApiScope;
use App\PublicApi\Security\PartnerUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Le périmètre et la page d'une lecture `/v1`, lus UNE fois et pour toutes les ressources.
 *
 * ⚠ **LE PÉRIMÈTRE VIENT DE `PartnerUser::establishmentsFor()`, JAMAIS DE LA REQUÊTE.** Le filtre
 * `establishment` ne peut que RESTREINDRE ce périmètre : un établissement qui n'y est pas rend 404
 * (pas 403, pas une liste vide), la même réponse que s'il n'existait pas. Sans accord portant la
 * portée, le périmètre est vide et chaque ressource rend une liste vide (refus par défaut, spec §2).
 *
 * Pagination par curseur : l'identifiant du dernier élément servi, opaque (base64url), sur un ordre
 * total et stable (`id` croissant). Pas de numéro de page : une insertion pendant le parcours ne fait
 * ni doublon ni trou dans ce qui était déjà là.
 */
final class PartnerReadQuery
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;

    /** @param list<Uuid> $establishments */
    private function __construct(
        public readonly array $establishments,
        public readonly int $limit,
        public readonly ?Uuid $cursor,
        public readonly ?\DateTimeImmutable $updatedSince,
    ) {
    }

    public static function fromRequest(Request $request, PartnerUser $partner, ApiScope $scope): self
    {
        $establishments = $partner->establishmentsFor($scope);

        $wanted = $request->query->get('establishment');
        if (null !== $wanted && '' !== $wanted) {
            $match = array_values(array_filter(
                $establishments,
                static fn (Uuid $id): bool => (string) $id === strtolower((string) $wanted),
            ));
            if ([] === $match) {
                throw new NotFoundHttpException('Établissement introuvable.');
            }
            $establishments = $match;
        }

        $limit = $request->query->getInt('limit', self::DEFAULT_LIMIT);
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new BadRequestHttpException(sprintf('`limit` va de 1 à %d.', self::MAX_LIMIT));
        }

        return new self($establishments, $limit, self::cursor($request->query->get('cursor')), self::since($request->query->get('updatedSince')));
    }

    public static function encodeCursor(Uuid $id): string
    {
        return rtrim(strtr(base64_encode($id->toBinary()), '+/', '-_'), '=');
    }

    private static function cursor(mixed $raw): ?Uuid
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $binary = \is_string($raw) ? base64_decode(strtr($raw, '-_', '+/'), true) : false;
        if (false === $binary || 16 !== \strlen($binary)) {
            throw new BadRequestHttpException('`cursor` illisible : reprenez celui que la page précédente a rendu.');
        }

        return Uuid::fromBinary($binary);
    }

    private static function since(mixed $raw): ?\DateTimeImmutable
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $date = \is_string($raw) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $raw) : false;
        if (false === $date) {
            throw new BadRequestHttpException('`updatedSince` attend une date ISO 8601 avec fuseau (ex. 2026-10-04T08:00:00+00:00).');
        }

        return $date;
    }
}
