<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Service\VitrineResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * `GET /boutique/vitrine-courante` — rend la vitrine désignée par l'hôte, ou 404 (D104).
 *
 * ── CE PROVIDER NE DÉCIDE DE RIEN, ET C'EST VOULU ────────────────────────────────────────────────
 *
 * Il ne sait ni découper un hôte, ni ce qu'est une vitrine publiée. Les deux vivent dans
 * `VitrineResolver`, à côté de la résolution par identifiant et par slug, parce que le jour où l'une
 * des règles bouge, elle doit bouger pour tout le monde en même temps.
 *
 * ⚠ **404, jamais une vitrine par défaut.** L'en-tête `Host` est fourni par l'appelant, et aucun
 * `trusted_hosts` n'est déclaré dans ce dépôt. Un hôte inconnu, une étiquette réservée, un domaine
 * qui ressemble au nôtre sans l'être : tout cela doit sortir par le même refus. Rendre « la première
 * vitrine » ou « celle par défaut » servirait les données d'un client à quiconque tape un nom au
 * hasard.
 *
 * La forme rendue est **exactement** celle de `/boutique/vitrines-publiques` : identifiant, nom
 * d'affichage, marque. Aucune donnée interne — canaux actifs, délai d'expiration de panier,
 * établissement complet — ne sort par ici.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class CurrentStorefrontProvider implements ProviderInterface
{
    public function __construct(
        private readonly VitrineResolver $resolveur,
        private readonly RequestStack $requetes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $requete = $this->requetes->getCurrentRequest();
        $vitrine = $this->resolveur->resoudreParHote($requete?->getHost());

        // Une vitrine dépubliée est introuvable, pas « trouvée mais vide » : le visiteur qui tape
        // l'adresse d'une boutique fermée doit voir la même chose que celui qui invente un nom.
        if ($vitrine === null || !$this->resolveur->estVisibleDuPublic($vitrine)) {
            return new JsonResponse(
                ['detail' => 'Aucune boutique ne correspond à cette adresse.'],
                JsonResponse::HTTP_NOT_FOUND
            );
        }

        $etablissement = $vitrine->getEtablissement();
        \assert($etablissement !== null);

        return new JsonResponse([
            'id' => (string) $vitrine->getId(),
            'slug' => $vitrine->getSlug(),
            'nom' => $etablissement->getNom(),
            'logo' => $vitrine->getLogo(),
            'couleurs' => $vitrine->getCouleurs(),
            'langues' => $vitrine->getLangues(),
        ]);
    }
}
