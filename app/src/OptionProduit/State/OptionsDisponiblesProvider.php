<?php

declare(strict_types=1);

namespace App\OptionProduit\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Offre\Entity\Produit;
use App\OptionProduit\Entity\OptionProduit;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /produits/{produitId}/options-disponibles` (T6, RG-OPT-07/08, CA-8) : options actives
 * proposables pour ce produit sur l'établissement actif (en-tête `X-Etablissement`), triées par
 * ordre d'affichage. Même logique de filtrage que `AjoutLigneHandler::resoudreOptions()` (actif +
 * restriction établissement) mais lecture seule, sans résolution de prix.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class OptionsDisponiblesProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexteEtablissement,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $produitId = $this->resoudreProduitId($uriVariables);
        $produit = $produitId !== null ? $this->em->getRepository(Produit::class)->find($produitId) : null;
        if (!$produit instanceof Produit) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        $etablissement = $this->contexteEtablissement->etablissementActif();

        /** @var list<OptionProduit> $liaisons */
        $liaisons = $this->em->getRepository(OptionProduit::class)->findBy(
            ['produit' => $produit, 'actif' => true],
        );

        $groupes = [];
        foreach ($liaisons as $liaison) {
            $groupe = $liaison->getGroupeOption();
            if ($groupe === null || !$groupe->isActif()) {
                continue;
            }
            $restrictions = $liaison->getEtablissementsRestriction();
            if (!$restrictions->isEmpty() && ($etablissement === null || !$restrictions->contains($etablissement))) {
                continue; // RG-OPT-07 : hors établissement actif.
            }

            $valeurs = [];
            foreach ($groupe->getValeurs() as $valeur) {
                if (!$valeur->isActif()) {
                    continue;
                }
                $valeurs[] = [
                    'valeurOption' => (string) $valeur->getId(),
                    'libelle' => $valeur->getLibelle(),
                    'impactType' => $valeur->getImpactType()?->value,
                    'impactValeur' => $valeur->getImpactValeur(),
                    'ordreAffichage' => $valeur->getOrdreAffichage(),
                ];
            }
            usort($valeurs, static fn (array $a, array $b): int => $a['ordreAffichage'] <=> $b['ordreAffichage']);

            $groupes[] = [
                'optionProduit' => (string) $liaison->getId(),
                'groupeOption' => (string) $groupe->getId(),
                'libelle' => $groupe->getLibelle(),
                'modeSelection' => $groupe->getModeSelection()?->value,
                'obligatoire' => $liaison->isObligatoire(),
                'ordreAffichage' => $liaison->getOrdreAffichage(),
                'valeurs' => $valeurs,
            ];
        }
        usort($groupes, static fn (array $a, array $b): int => $a['ordreAffichage'] <=> $b['ordreAffichage']);

        return new JsonResponse(['produit' => (string) $produit->getId(), 'groupes' => $groupes]);
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function resoudreProduitId(array $uriVariables): ?string
    {
        foreach (['produitId', 'produit_id', 'id'] as $cle) {
            $valeur = $uriVariables[$cle] ?? null;
            if (\is_string($valeur) && $valeur !== '' && Uuid::isValid(basename($valeur))) {
                return basename($valeur);
            }
        }

        // Filet de sécurité : extraction directe depuis le chemin de la requête (comportement
        // observé de `$uriVariables` non garanti pour une clé personnalisée sur `GetCollection`,
        // même limitation que `App\Support\State\HistoriqueArticleProvider`).
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && preg_match('#/produits/([^/]+)/options-disponibles#', $request->getPathInfo(), $m) === 1 && Uuid::isValid($m[1])) {
            return $m[1];
        }

        return null;
    }
}
