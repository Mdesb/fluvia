<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Security\VitrineAccessibleGuard;
use App\Boutique\Service\DisponibiliteAffichageHandler;
use App\Boutique\Service\VitrineResolver;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Reservation\Entity\Creneau;
use App\Reservation\Enum\StatutCreneau;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/produits/{produitId}/creneaux (US-L8-02, RG-M3-02, CA-2) : les créneaux qu'un
 * produit à horaire propose en ligne — délègue au module `reservation` (`Creneau`).
 *
 * ── LE PRODUIT DÉSIGNE SES CRÉNEAUX PAR L'ACTIVITÉ, ET C'EST L'ARBITRAGE DE MAXIME (07/09) ────
 *
 * `Activite::$produitTarifReference` est une relation typée, écrite depuis l'écran des activités de
 * réservation, et c'est le seul lien que le planning connaisse entre ce qu'il programme et ce que
 * le catalogue vend. Une même visite guidée programmée dans deux salles, ou avec deux guides, est
 * **une** activité et deux ressources : la vendre par la ressource n'en aurait montré qu'une.
 *
 * Mesure qui a décidé (préprod, 07/09) : « Visite guidée (créneau) » portait
 * `champsPerso['ressourceId']` vers une ressource ne comptant qu'un créneau terminé, pendant que
 * son activité en portait seize, dont neuf à venir. La boutique annonçait « Horaire à choisir » et
 * n'affichait rien.
 *
 * ── LE PÉRIMÈTRE EST PORTÉ ICI, PARCE QUE RIEN D'AUTRE NE LE PORTE ────────────────────────────
 *
 * Cette route est en `PUBLIC_ACCESS` : aucune extension Doctrine de cloisonnement ne s'applique à
 * un `createQueryBuilder` écrit à la main. Deux établissements qui diffusent le même produit ont
 * chacun leurs activités ; sans filtre, la boutique de l'un afficherait les horaires de l'autre.
 *
 * `?vitrine=<id|slug>` restreint donc à l'établissement de cette vitrine — mêmes règles d'accès que
 * le catalogue (`VitrineAccessibleGuard` : établissement actif, canal en ligne ouvert). Sans ce
 * paramètre, on se limite aux établissements où le produit est **diffusé**, ce qui reste le
 * périmètre le plus large qu'un catalogue public puisse légitimement montrer.
 *
 * ⚠ UNE VITRINE FOURNIE ET NON RÉSOLUE EST UN 404, jamais un repli sur le périmètre large : un
 * repli ferait du paramètre une décoration, et il suffirait de l'écrire faux pour l'annuler.
 *
 * ⚠ ET DEUX CRÉNEAUX NE SONT PAS PROPOSÉS, POUR DEUX RAISONS DIFFÉRENTES :
 *   - `publicReserve` (RG-M5-08) — le créneau existe, il n'est pas pour ce public-ci ;
 *   - `enAttenteArbitrage` — `ReserverProcessor` le refuse (décision du 31/08 : une occurrence de
 *     récurrence en conflit est créée plutôt que perdue, et attend qu'un humain tranche). Le
 *     proposer en ligne vendrait une place que le serveur refusera ensuite, et l'écran de la
 *     boutique n'a aucun moyen de l'expliquer au client.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class CreneauxProduitProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisponibiliteAffichageHandler $disponibilite,
        private readonly VitrineResolver $vitrines,
        private readonly VitrineAccessibleGuard $vitrineGuard,
        private readonly RequestStack $requetes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $produitId = PanierProprietaireGuard::estUuid($uriVariables['id'] ?? null);
        $produit = $produitId !== null ? $this->em->getRepository(Produit::class)->find($produitId) : null;
        if (!$produit instanceof Produit) {
            throw new NotFoundHttpException('Produit introuvable.');
        }
        // Revue de sécurité — faille majeure : un produit non publié ou non visible au canal en ligne
        // ne doit jamais exposer ses créneaux publiquement (même garde qu'à l'ajout au panier).
        if ($produit->getStatut() !== StatutProduit::Publie || !$produit->aCanal(Canal::EnLigne)) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        $etablissements = $this->perimetre($produit);
        if ($etablissements === []) {
            return $this->reponse($produit, []);
        }

        $qb = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->join('c.activite', 'a')
            ->andWhere('a.produitTarifReference = :produit')
            ->andWhere('a.actif = true')
            ->andWhere('c.statut = :planifie')
            ->andWhere('c.enAttenteArbitrage = false')
            ->andWhere('c.debut >= :maintenant')
            // D58 — l'identifiant et son type, jamais l'entité.
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->setParameter('planifie', StatutCreneau::Planifie->value)
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->orderBy('c.debut', 'ASC');

        // ⚠ UNE ÉGALITÉ PAR ÉTABLISSEMENT, ET NON UN `IN (:liste)`. En DQL, `IN` ne convertit pas
        // les UUID : la requête ne lève pas, elle rend zéro — le catalogue afficherait « aucun
        // horaire » sur un planning plein. Même forme que `PerimetreEtablissementExtension`, pour
        // la même raison, et c'est celle que le garde-fou D58 accepte.
        $termes = [];
        foreach ($etablissements as $rang => $identifiant) {
            $cle = 'perimetre_etablissement_'.$rang;
            $termes[] = 'a.etablissement = :'.$cle;
            $qb->setParameter($cle, $identifiant, 'uuid');
        }
        $qb->andWhere((string) $qb->expr()->orX(...$termes));

        $liste = [];
        foreach ($qb->getQuery()->getResult() as $creneau) {
            \assert($creneau instanceof Creneau);
            // RG-M5-08 : un créneau à public réservé n'apparaît pas dans le sélecteur en ligne.
            if ($creneau->getPublicReserve() !== null && $creneau->getPublicReserve() !== '') {
                continue;
            }
            $liste[] = [
                'creneau' => (string) $creneau->getId(),
                'debut' => $creneau->getDebut()->format(DATE_ATOM),
                'fin' => $creneau->getFin()->format(DATE_ATOM),
                'reste' => $this->disponibilite->disponibilitePourCreneau($creneau),
            ];
        }

        return $this->reponse($produit, $liste);
    }

    /**
     * Les établissements dont les activités peuvent alimenter ce produit en ligne.
     *
     * @return list<\Symfony\Component\Uid\Uuid>
     */
    private function perimetre(Produit $produit): array
    {
        $demandee = $this->requetes->getCurrentRequest()?->query->get('vitrine');

        if (\is_string($demandee) && $demandee !== '') {
            $vitrine = $this->vitrines->resoudre($demandee);
            if (!$vitrine instanceof Vitrine) {
                throw new NotFoundHttpException('Vitrine introuvable.');
            }
            $this->vitrineGuard->verifier($vitrine);

            $etablissement = $vitrine->getEtablissement();
            // Le produit n'est pas diffusé chez ce vendeur : sa boutique n'a aucun horaire à en
            // montrer. Une liste vide, et non un 404 : le produit existe, il n'est pas vendu ici.
            if ($etablissement === null || !$produit->getEtablissements()->contains($etablissement)) {
                return [];
            }

            return [$etablissement->getId()];
        }

        $identifiants = [];
        foreach ($produit->getEtablissements() as $etablissement) {
            $identifiants[] = $etablissement->getId();
        }

        return $identifiants;
    }

    /** @param list<array<string, mixed>> $creneaux */
    private function reponse(Produit $produit, array $creneaux): JsonResponse
    {
        return new JsonResponse(['produit' => (string) $produit->getId(), 'creneaux' => $creneaux]);
    }
}
