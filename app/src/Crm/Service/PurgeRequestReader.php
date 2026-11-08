<?php

declare(strict_types=1);

namespace App\Crm\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Lit et valide les critères d'une épuration — les mêmes pour l'aperçu et pour l'application, pour
 * que ce qui a été montré soit ce qui est appliqué.
 *
 * `inactiveMonths` : entier de 1 à 240 (vingt ans). La date-seuil est calculée ICI, au serveur ; le
 * navigateur n'envoie jamais de date-seuil qu'il aurait pu mal calculer ou falsifier.
 * `createdBefore`  : facultatif, `AAAA-MM-JJ` — ne retenir que les fiches créées avant ce jour.
 */
final class PurgeRequestReader
{
    public const MAX_MONTHS = 240;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        $contenu = $this->requestStack->getCurrentRequest()?->getContent() ?? '';
        $decode = $contenu === '' ? [] : json_decode($contenu, true);

        return \is_array($decode) ? $decode : [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable, 2: int} seuil d'inactivité, créé avant, mois
     */
    public function criteria(array $body, ?\DateTimeImmutable $now = null): array
    {
        $mois = $body['inactiveMonths'] ?? null;
        if (!\is_int($mois) || $mois < 1 || $mois > self::MAX_MONTHS) {
            throw new UnprocessableEntityHttpException(sprintf('inactiveMonths : un nombre entier de mois, de 1 à %d.', self::MAX_MONTHS));
        }
        $now ??= new \DateTimeImmutable();
        $seuil = $now->modify(sprintf('-%d months', $mois));

        $createdBefore = null;
        $brut = $body['createdBefore'] ?? null;
        if ($brut !== null && $brut !== '') {
            $date = \is_string($brut) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $brut) : false;
            if ($date === false || $date->format('Y-m-d') !== $brut) {
                throw new UnprocessableEntityHttpException('createdBefore : une date au format AAAA-MM-JJ.');
            }
            $createdBefore = $date;
        }

        return [$seuil, $createdBefore, $mois];
    }
}
