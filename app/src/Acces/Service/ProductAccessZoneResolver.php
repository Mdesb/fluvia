<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\ProductAccessZone;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recopie sur un droit d'accès les zones déclarées pour son produit.
 *
 * La déclaration vit sur le produit ; la DÉCISION se prend sur le droit, parce que c'est le droit que
 * les terminaux embarquent pour statuer hors ligne. Ce service est le pont entre les deux, et le seul.
 *
 * ── LA RECOPIE EST UNE SYNCHRONISATION, PAS UN AJOUT ────────────────────────────────────────────
 *
 * Une re-projection (ré-appairage après perte, rechargement d'une carte) repasse ici avec une
 * déclaration qui a pu changer entre-temps. Ajouter sans retirer laisserait vivre indéfiniment une
 * zone que l'exploitant a explicitement retirée du produit : le retrait n'aurait aucun effet sur les
 * titres déjà émis, et il n'aurait aucun effet SANS RIEN DIRE.
 *
 * D'où le calcul de différence dans les deux sens — et d'où l'existence de
 * `DroitAcces::removeAuthorisedSpace()`, qui trouve ici son premier appelant réel.
 *
 * ── CE QUE CE SERVICE NE FAIT PAS, ET QU'IL FAUT SAVOIR ─────────────────────────────────────────
 *
 * Il n'agit qu'AU MOMENT DE LA PROJECTION. Déclarer une zone sur un produit ne restreint donc pas
 * rétroactivement les titres déjà vendus : leur copie date de leur émission et ne bouge qu'à la
 * prochaine re-projection.
 *
 * C'est le comportement sûr des deux. L'inverse — recalculer tous les droits existants à chaque
 * modification d'un produit — refuserait du jour au lendemain des porteurs qui ont payé, sur un
 * réglage fait pour les ventes à venir. Si un jour on veut la reprise, elle doit être un GESTE
 * explicite de l'exploitant, avec son décompte affiché avant exécution, pas un effet de bord.
 *
 * ── AUCUNE DÉCLARATION = AUCUNE PORTE (D87) ─────────────────────────────────────────────────────
 *
 * Un produit sans ligne vide la collection du droit, et un droit sans espace n'ouvre rien (cf.
 * `DroitAcces::ouvre()`, strict depuis le 30/08). La garde de publication empêche d'en PUBLIER un
 * là où le contrôle d'accès est actif (`AccessZonePublicationPrerequisite`) ; un produit déjà
 * publié, lui, continue de se vendre.
 */
final class ProductAccessZoneResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<EspaceAcces> les zones déclarées pour ce produit sur cet établissement
     */
    public function spacesFor(?Uuid $productRef, Etablissement $establishment): array
    {
        if ($productRef === null) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('z')
            ->from(ProductAccessZone::class, 'z')
            // ⚠ D58 : `productRef` est une colonne `uuid` nue, sans relation Doctrine. Sans le type
            // explicite en troisième argument, la comparaison ne trouve RIEN et ne lève RIEN — le
            // droit sortirait sans zone, donc n'ouvrant aucune porte (D87), sans rien signaler.
            ->andWhere('z.productRef = :product')
            ->setParameter('product', $productRef, 'uuid')
            ->andWhere('IDENTITY(z.establishment) = :establishment')
            ->setParameter('establishment', $establishment->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        return array_map(static fn (ProductAccessZone $z): EspaceAcces => $z->getSpace(), $rows);
    }

    /**
     * Aligne les zones autorisées d'un droit sur la déclaration courante de son produit.
     */
    public function applyTo(DroitAcces $right, ?Uuid $productRef, Etablissement $establishment): void
    {
        $declared = $this->spacesFor($productRef, $establishment);

        // Comparaison sur la représentation textuelle des identifiants : `getId()` rend des objets
        // `Uuid`, que `in_array()` strict distinguerait à tort alors qu'ils désignent la même zone.
        $declaredIds = array_map(static fn (EspaceAcces $s): string => (string) $s->getId(), $declared);

        foreach ($right->getAuthorisedSpaces()->toArray() as $current) {
            if (!\in_array((string) $current->getId(), $declaredIds, true)) {
                $right->removeAuthorisedSpace($current);
            }
        }

        foreach ($declared as $space) {
            $right->addAuthorisedSpace($space);
        }
    }
}
