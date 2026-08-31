<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\Referral;
use App\Marketing\Service\MarketingContext;
use App\Marketing\Service\ReferralService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /marketing/parrainages/{id}/recompenser` — verser les points au parrain.
 *
 * Le versement est **explicite**, jamais automatique à la lecture. Une lecture qui écrit est une
 * surprise : un rapport consulté deux fois verserait deux fois, et rien dans l'écran ne dirait que
 * l'argent est parti.
 *
 * Trois refus possibles, et chacun dit lequel : déjà récompensé (409), filleul sans achat (422),
 * hors périmètre (404).
 *
 * @cloisonnement-verifie : le parrainage est résolu par identifiant, PUIS l'autorité est recalculée
 * contre l'établissement de la ligne résolue — `MarketingContext::exigerAutoriteSur()`, qui appelle
 * `codesEffectifs()` sur cet établissement-là et lève un 404. Le contrôle n'est pas absent, il est
 * écrit une seule fois pour les six points d'entrée du module ; le garde-fou ne sait pas suivre la
 * délégation, d'où cette déclaration. — claude-A, 28/08
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final readonly class ReferralRewardProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketingContext $contexte,
        private ReferralService $parrainage,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        $lien = $id === null ? null : $this->entityManager->getRepository(Referral::class)->find($id);

        if (!$lien instanceof Referral) {
            throw new NotFoundHttpException('Parrainage introuvable.');
        }

        // L'autorité se recalcule contre l'établissement DU PARRAINAGE, jamais contre celui de
        // l'en-tête : `X-Etablissement` est un sélecteur, pas une preuve (D6/C19). Et le refus est
        // un 404 : un 403 confirmerait que ce parrainage existe ailleurs.
        $this->contexte->exigerAutoriteSur($lien->getEstablishment()?->getId(), 'fidelite', 'gerer');

        $ecriture = $this->parrainage->recompenser($lien);

        return new JsonResponse([
            'parrainage' => (string) $lien->getId(),
            'parrain' => (string) $lien->getSponsorRef(),
            'pointsVerses' => $ecriture->getPoints(),
            'le' => $lien->getRewardedAt()?->format(\DATE_ATOM),
        ], 201);
    }
}
