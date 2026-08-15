<?php

declare(strict_types=1);

namespace App\Fonctionnalite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fonctionnalite\Entity\FonctionnaliteEtablissement;
use App\Fonctionnalite\Security\GardeFonctionnaliteEtablissement;
use App\Fonctionnalite\Service\Fonctionnalites;
use Doctrine\ORM\EntityManagerInterface;

/**
 * GET /etablissements/{id}/fonctionnalites : état de **toutes** les capacités du catalogue pour cet
 * établissement (actives ou non), afin que l'UI n'affiche que le pertinent (règle d'or §2 constitution.md).
 *
 * @implements ProviderInterface<list<FonctionnaliteEtablissement>>
 */
final class EtatFonctionnalitesProvider implements ProviderInterface
{
    use ResolutionEtablissementCheminTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GardeFonctionnaliteEtablissement $garde,
        private readonly Fonctionnalites $fonctionnalites,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $etablissement = $this->resoudreEtablissement($this->em, $uriVariables);
        $this->garde->verifierLecture($etablissement);

        return $this->fonctionnalites->etat($etablissement);
    }
}
