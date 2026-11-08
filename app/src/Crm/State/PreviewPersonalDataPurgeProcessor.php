<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Crm\Security\CustomerGroupGuard;
use App\Crm\Service\PurgeCandidateFinder;
use App\Crm\Service\PurgeRequestReader;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * POST /rgpd/purge/preview (#344) : ce qu'une épuration toucherait. N'écrit RIEN.
 *
 * Rend trois nombres et une liste :
 *   - `inactive`  : fiches inactives depuis la date-seuil ;
 *   - `protected` : parmi elles, celles qu'une protection retient (abonnement vivant, solde positif) ;
 *   - `eligible`  : celles qu'une épuration anonymiserait ;
 *   - `candidates`: les `LIMIT` premières éligibles, pour que l'administrateur décoche qui il veut.
 *
 * `protected` est rendu exprès : sans lui, « 0 fiche à épurer » se lirait « personne d'inactif »,
 * alors que c'est peut-être « deux cents inactifs, tous avec un solde ».
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class PreviewPersonalDataPurgeProcessor implements ProcessorInterface
{
    public const LIMIT = 500;

    public function __construct(
        private readonly CustomerGroupGuard $guard,
        private readonly PurgeCandidateFinder $finder,
        private readonly PurgeRequestReader $reader,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $groupe = $this->guard->activeGroup();
        [$seuil, $createdBefore, $mois] = $this->reader->criteria($this->reader->body());

        $inactive = $this->finder->countInactive($groupe, $seuil, $createdBefore);
        $eligible = $this->finder->countEligible($groupe, $seuil, $createdBefore);
        $candidats = $this->finder->candidates($groupe, $seuil, $createdBefore, self::LIMIT);

        return new JsonResponse([
            'inactiveMonths' => $mois,
            'inactiveSince' => $seuil->format(DATE_ATOM),
            'createdBefore' => $createdBefore?->format('Y-m-d'),
            'inactive' => $inactive,
            'protected' => $inactive - $eligible,
            'eligible' => $eligible,
            'limit' => self::LIMIT,
            'candidates' => array_map(static fn (Client $c): array => [
                'id' => (string) $c->getId(),
                'nom' => $c->getNom(),
                'prenom' => $c->getPrenom(),
                'raisonSociale' => $c->getRaisonSociale(),
                'email' => $c->getEmail(),
                'statut' => $c->getStatut()->value,
                'dateCreation' => $c->getDateCreation()->format(DATE_ATOM),
                'dateDerniereVisite' => $c->getDateDerniereVisite()?->format(DATE_ATOM),
            ], $candidats),
        ]);
    }
}
