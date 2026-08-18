<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Security\VitrineAccessibleGuard;
use App\Boutique\Service\DisponibiliteAffichageHandler;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\ResolveurPrix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/vitrines/{id}/catalogue (US-L8-01, RG-M3-01/08, CA-1) : catalogue public — ne montre
 * que les produits **publiés** et **visibles au canal `en_ligne`** (RG-M1-07/09), prix/disponibilité
 * en temps réel (aucun décalage avec M1/le stock). Comble des manques boutique (tunnel public) :
 * expose désormais le **prix public** (fourchette « à partir de » calculée via `ResolveurPrix` M1, aucun
 * prix recodé) et le **visuel** du produit s'il existe (`Produit.champsPerso['visuelUrl']`, même
 * convention que `champsPerso['timedEntry']`/`champsPerso['ressourceId']` déjà utilisée par ce module —
 * aucune facette visuel dédiée côté M1).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class CatalogueVitrineProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisponibiliteAffichageHandler $disponibilite,
        private readonly ResolveurPrix $resolveurPrix,
        private readonly VitrineAccessibleGuard $vitrineGuard,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = PanierProprietaireGuard::estUuid($uriVariables['id'] ?? null);
        $vitrine = $id !== null ? $this->em->getRepository(Vitrine::class)->find($id) : null;
        if (!$vitrine instanceof Vitrine) {
            throw new NotFoundHttpException('Vitrine introuvable.');
        }
        // Revue de sécurité — faille majeure : établissement inactif ou canal `en_ligne` coupé ->
        // catalogue jamais exposé publiquement (même règle que `VitrinesPubliquesProvider`).
        $this->vitrineGuard->verifier($vitrine);
        $etablissement = $vitrine->getEtablissement();

        $produits = $this->em->getRepository(Produit::class)->createQueryBuilder('p')
            ->innerJoin('p.etablissements', 'e')
            ->andWhere('e = :etablissement')
            ->andWhere('p.statut = :publie')
            ->setParameter('etablissement', $etablissement?->getId(), 'uuid')
            ->setParameter('publie', StatutProduit::Publie->value)
            ->getQuery()
            ->getResult();

        $catalogue = [];
        foreach ($produits as $produit) {
            \assert($produit instanceof Produit);
            if (!$produit->aCanal(Canal::EnLigne)) {
                continue;
            }
            $catalogue[] = [
                'produit' => (string) $produit->getId(),
                'code' => $produit->getCode(),
                'libelle' => $produit->getLibelle(),
                'timedEntry' => $this->disponibilite->estTimedEntry($produit),
                'disponibilite' => $this->disponibilite->disponibilitePourProduit($produit),
                'visuel' => \is_string($produit->getChampsPerso()['visuelUrl'] ?? null) ? $produit->getChampsPerso()['visuelUrl'] : null,
                'prix' => $this->prixPublic($produit),
            ];
        }

        return new JsonResponse([
            'vitrine' => (string) $vitrine->getId(),
            'logo' => $vitrine->getLogo(),
            'couleurs' => $vitrine->getCouleurs(),
            'langues' => $vitrine->getLangues(),
            'produits' => $catalogue,
        ]);
    }

    /**
     * Prix public résolu pour chaque type de tarif commercialisé au canal `en_ligne` aujourd'hui
     * (`App\Offre\Service\ResolveurPrix`, moteur M1 réutilisé tel quel). `min`/`max` permettent au
     * front d'afficher « à partir de X € » dès qu'au moins deux tarifs distincts sont commercialisés ;
     * `null` si le produit publié n'a en réalité aucun prix résolu en ligne (anomalie de données,
     * ne doit normalement pas se produire pour un produit publié — RG-M1-09).
     *
     * @return array{min: string, max: string}|null
     */
    private function prixPublic(Produit $produit): ?array
    {
        $prix = [];
        $maintenant = new \DateTimeImmutable();
        foreach ($produit->getGrilles() as $grille) {
            $typeTarif = $grille->getTypeTarif();
            if ($typeTarif === null) {
                continue;
            }
            $resolu = $this->resolveurPrix->resoudre($produit, $typeTarif, $maintenant, Canal::EnLigne);
            if ($resolu !== null) {
                $prix[] = (float) $resolu;
            }
        }
        if ($prix === []) {
            return null;
        }

        return [
            'min' => number_format(min($prix), 2, '.', ''),
            'max' => number_format(max($prix), 2, '.', ''),
        ];
    }
}
