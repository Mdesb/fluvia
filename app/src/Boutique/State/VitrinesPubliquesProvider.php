<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\Vitrine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /boutique/vitrines-publiques — comble des manques boutique : listing public léger, sans
 * permission staff `boutique.lire`. Ne renvoie que les vitrines **publiées/actives** — établissement
 * actif (`Etablissement::isActif()`) et canal `en_ligne` activé sur la vitrine
 * (`Vitrine::getCanauxActifs()`, RG-M3-01) — avec uniquement des données publiques (id, nom
 * d'affichage, branding). Aucune donnée interne (établissement complet, canaux, délai d'expiration
 * panier...) n'est exposée.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class VitrinesPubliquesProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $vitrines = [];
        foreach ($this->em->getRepository(Vitrine::class)->findAll() as $vitrine) {
            \assert($vitrine instanceof Vitrine);
            $etablissement = $vitrine->getEtablissement();
            if ($etablissement === null || !$etablissement->isActif()) {
                continue;
            }
            if (!\in_array('en_ligne', $vitrine->getCanauxActifs(), true)) {
                continue;
            }

            $vitrines[] = [
                'id' => (string) $vitrine->getId(),
                'nom' => $etablissement->getNom(),
                'logo' => $vitrine->getLogo(),
                'couleurs' => $vitrine->getCouleurs(),
                'langues' => $vitrine->getLangues(),
            ];
        }

        return new JsonResponse(['vitrines' => $vitrines]);
    }
}
