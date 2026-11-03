<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Offre\Service\PublicationGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /produits/readiness-preview : ce qui manquerait pour publier un produit PAS ENCORE CRÉÉ.
 *
 * La modale de création (refonte, lot 1) propose « Créer et mettre en vente » quand rien ne manque,
 * et dit sinon ce qui restera à faire sur la fiche. Décider cela côté écran aurait réécrit la garde
 * de publication — et ses prérequis de modules (zone d'accès quand le site contrôle les accès,
 * créneau pour un produit horaire vendu en ligne), qui changeraient sans que l'écran le sache.
 *
 * Ici le corps est celui de `POST /produits` (mêmes groupes, mêmes validations, mêmes IRI résolues à
 * travers le cloisonnement), préparé par `ProduitProcessor::preparer()` exactement comme une création
 * — catégories par défaut du type, site actif — puis soumis à `PublicationGuard::missing()`, la
 * règle de `/readiness` et de la publication.
 *
 * ⚠ RIEN N'EST ÉCRIT. Le produit n'est jamais passé à `persist()` : aucune cascade ne l'atteint
 * (`Produit` n'est la cible d'aucune association en cascade), et aucun code ne lui est attribué
 * (le générateur consomme une séquence).
 *
 * ⚠ LE PRIX : il naît APRÈS le produit (`POST /grille_tarifaires`), donc le produit en mémoire n'en a
 * jamais. Le corps peut porter `prixPrevu` (le montant saisi dans la modale) : s'il est un nombre
 * positif ou nul, le manque `prix` est retiré — c'est la seule entorse, et elle est nommée.
 *
 * @implements ProcessorInterface<Produit, JsonResponse>
 */
final class ReadinessPreviewProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProduitProcessor $produits,
        private readonly PublicationGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Produit);
        $this->produits->preparer($data, $operation);

        $missing = $this->guard->missing($data);
        if ($this->prixPrevu($context['request'] ?? null)) {
            unset($missing['prix']);
        }

        $lines = [];
        foreach ($missing as $code => $message) {
            $lines[] = ['code' => $code, 'message' => $message];
        }

        return new JsonResponse(['missing' => $lines]);
    }

    private function prixPrevu(mixed $request): bool
    {
        if (!$request instanceof Request) {
            return false;
        }
        try {
            $prix = $request->toArray()['prixPrevu'] ?? null;
        } catch (\Throwable) {
            return false;
        }

        return \is_numeric($prix) && (float) $prix >= 0;
    }
}
