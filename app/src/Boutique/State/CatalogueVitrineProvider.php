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

        // LA JOINTURE EST EXTERNE, ET C'EST TOUT LE CORRECTIF.
        //
        // En interne, un produit SANS etablissement ne satisfait aucune ligne. Or, au 27/08, les sept
        // produits publies et vendables en ligne du jeu de demonstration -- audioguide, abonnement,
        // pass musee, expo Egypte, boutique -- avaient TOUS un etablissement nul. Les deux seuls
        // produits rattaches a un site etaient en brouillon, guichet uniquement.
        //
        // Resultat : LES DEUX BOUTIQUES EN LIGNE N'AVAIENT JAMAIS RIEN EU A VENDRE, depuis la creation
        // du jeu de demonstration. Elles affichaient << Aucun billet en vente >> -- une phrase exacte,
        // qui ne ressemblait pas a un defaut.
        //
        // C'est la meme cause que la fuite du catalogue back-office corrigee le meme jour : dans ce
        // depot, << sans etablissement >> veut dire << socle, partage par tous >> pour cette entite --
        // elle n'a ni discriminant `portee` ni colonne `etablissement`, donc ni l'un ni l'autre des
        // deux patrons de D51. Une jointure interne la traite comme n'appartenant a personne.
        //
        // Regle retenue par Maxime le 27/08 : etablissement actif PLUS socle.
        $produits = $this->em->getRepository(Produit::class)->createQueryBuilder('p')
            ->leftJoin('p.etablissements', 'e')
            ->andWhere('(e.id = :etablissement OR SIZE(p.etablissements) = 0)')
            ->andWhere('p.statut = :publie')
            // Type `uuid` explicite : sur un identifiant a type personnalise, une comparaison sans type
            // ne compte rien et NE LEVE PAS (D58). Ici elle ne rendrait pas << moins >> mais le socle
            // seul, ce qui ressemble a un catalogue mal configure bien plus qu'a un bug.
            ->setParameter('etablissement', $etablissement?->getId(), 'uuid')
            ->setParameter('publie', StatutProduit::Publie->value)
            ->distinct()
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
            // Necessaire au pied de page public : c'est par l'etablissement que se lisent les mentions
            // legales publiees. Le deduire cote client demanderait un second appel pour une donnee que
            // le serveur a deja en main.
            'etablissement' => (string) $etablissement?->getId(),
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
