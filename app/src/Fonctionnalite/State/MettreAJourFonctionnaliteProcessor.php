<?php

declare(strict_types=1);

namespace App\Fonctionnalite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fonctionnalite\Entity\FonctionnaliteEtablissement;
use App\Fonctionnalite\Security\GardeFonctionnaliteEtablissement;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * PATCH /etablissements/{id}/fonctionnalites (activer/désactiver/paramétrer une capacité). Corps :
 * { "capaciteCode": "poss", "active": true, "parametres"?: {...} }.
 *
 * @implements ProcessorInterface<mixed, FonctionnaliteEtablissement>
 */
final class MettreAJourFonctionnaliteProcessor implements ProcessorInterface
{
    use ResolutionEtablissementCheminTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GardeFonctionnaliteEtablissement $garde,
        private readonly Fonctionnalites $fonctionnalites,
        private readonly CatalogueCapacites $catalogue,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FonctionnaliteEtablissement
    {
        $etablissement = $this->resoudreEtablissement($this->em, $uriVariables);
        $this->garde->verifierGestion($etablissement);

        $corps = $this->lecteur->corps();
        $code = \is_string($corps['capaciteCode'] ?? null) ? $corps['capaciteCode'] : '';
        if ($code === '' || !$this->catalogue->existe($code)) {
            throw new UnprocessableEntityHttpException('« capaciteCode » est requis et doit être une capacité connue du catalogue.');
        }
        if (!\array_key_exists('active', $corps) || !\is_bool($corps['active'])) {
            throw new UnprocessableEntityHttpException('« active » (booléen) est requis.');
        }
        $parametres = \is_array($corps['parametres'] ?? null) ? $corps['parametres'] : null;

        return $this->fonctionnalites->definir($etablissement, $code, $corps['active'], $parametres);
    }
}
