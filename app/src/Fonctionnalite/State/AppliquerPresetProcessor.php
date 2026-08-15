<?php

declare(strict_types=1);

namespace App\Fonctionnalite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Security\GardeFonctionnaliteEtablissement;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /etablissements/{id}/appliquer-preset : active le jeu de capacités du preset de la verticale
 * demandée (additif, cf. `Fonctionnalites::appliquerPreset`). Corps : { "metier": "piscine"|"sport"|
 * "padel"|"patinoire"|"musee" }.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class AppliquerPresetProcessor implements ProcessorInterface
{
    use ResolutionEtablissementCheminTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GardeFonctionnaliteEtablissement $garde,
        private readonly Fonctionnalites $fonctionnalites,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $etablissement = $this->resoudreEtablissement($this->em, $uriVariables);
        $this->garde->verifierGestion($etablissement);

        $corps = $this->lecteur->corps();
        $metier = Metier::tryFrom(\is_string($corps['metier'] ?? null) ? $corps['metier'] : '');
        if ($metier === null) {
            $valeurs = implode(', ', array_map(static fn (Metier $m): string => $m->value, Metier::cases()));
            throw new UnprocessableEntityHttpException(sprintf('« metier » est requis et doit être l\'une des valeurs : %s.', $valeurs));
        }

        $codes = $this->fonctionnalites->appliquerPreset($etablissement, $metier);

        return new JsonResponse([
            'etablissement' => (string) $etablissement->getId(),
            'metier' => $metier->value,
            'capacitesActivees' => $codes,
        ]);
    }
}
