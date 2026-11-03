<?php

declare(strict_types=1);

namespace App\Acces\Projection;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Port\ProjectionDroitInterface;
use App\Acces\Service\CardExpiryCalculator;
use App\Acces\Service\ProductAccessZoneResolver;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Vente\Entity\BilletSupport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stub L3 du port de projection (T4) : lit `BilletSupport` (M2) et le `Produit`/`CarteMultiEntrees`
 * (M1) associés pour construire/rafraîchir la projection locale. Une carte multi-entrées projette un
 * droit `carte_quota` avec `creditRestant` = compostages restants ; sinon `billet`/`abonnement` avec
 * une fenêtre ouverte (⚠ la fenêtre métier précise relève de M1, cf. Risque n°8 du plan).
 *
 * **T6, CQ-1 — bundle de cohérence (RG-CQ1-04 appliqué à l'émission, signalé pour arbitrage à
 * l'intégrateur, cf. rapport d'implémentation) :** avant ce correctif, `fenetreFin` n'était JAMAIS
 * écrite pour un droit `CarteQuota`, y compris quand `CarteMultiEntrees::validiteDuree`/`dateButoir`
 * étaient renseignés — une carte multi-entrées vendue n'expirait donc jamais (gap préexistant, pas
 * introduit par ce lot). Ce correctif applique désormais `CardExpiryCalculator` à la PREMIÈRE
 * projection uniquement (`$estNouveau`, capturé avant que `$existant` ne soit écrasé) — jamais à une
 * re-projection (ré-appairage après perte/vol), pour ne pas « réinitialiser » la validité d'une carte
 * déjà rechargée. Changement de comportement observable : les cartes de démonstration/production
 * portant `validiteDuree`/`dateButoir` commencent désormais à expirer dès l'émission.
 */
final class StubProjectionDroit implements ProjectionDroitInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CardExpiryCalculator $cardExpiry,
        private readonly ProductAccessZoneResolver $accessZones,
    ) {
    }

    public function projeter(Uuid $billetSupportRef, Etablissement $etablissement): DroitAcces
    {
        $existant = $this->em->getRepository(DroitAcces::class)->findOneBy(['billetSupportRef' => $billetSupportRef]);
        $estNouveau = !$existant instanceof DroitAcces;
        $droit = $existant instanceof DroitAcces ? $existant : new DroitAcces();

        $droit->setBilletSupportRef($billetSupportRef)->setEtablissement($etablissement);

        $support = $this->em->getRepository(BilletSupport::class)->find($billetSupportRef);
        $produit = null;
        if ($support instanceof BilletSupport && $support->getLigne() !== null) {
            $produit = $this->em->getRepository(Produit::class)->find($support->getLigne()->getProduit());
        }

        if ($produit instanceof Produit && $produit->getCarte() !== null) {
            $carte = $produit->getCarte();
            $droit->setSourceType(TypeDroitAcces::CarteQuota);
            $droit->setCreditRestant($support?->getNbCompostages() ?? $carte->getStockCompostagesInitial());
            $droit->setProduitRef($produit->getId());
            if ($estNouveau) {
                // T6 — n'écrit fenetreFin qu'à la PREMIÈRE projection (cf. docblock de classe).
                $droit->setFenetreFin($this->cardExpiry->calculer($carte, null, new \DateTimeImmutable(), $etablissement));
            }
        } else {
            $droit->setSourceType(TypeDroitAcces::Billet);
            $droit->setCreditRestant(null);
            if ($produit instanceof Produit) {
                $droit->setProduitRef($produit->getId());
            }
        }

        // ⚠ APRES les deux branches, jamais dans l'une d'elles.
        //
        // Chacune pose `produitRef` a sa maniere ; une seule les rejoint. Recopier ici garantit que
        // le jour ou un troisieme type de titre apparait, il herite de la regle au lieu de
        // l'ignorer -- un oubli d'appel ne produirait pas d'erreur, il produirait un titre qui
        // n'ouvre aucune porte (D87), sans rien signaler.
        //
        // C'est une SYNCHRONISATION : une re-projection avec une declaration modifiee retire aussi
        // les zones qui n'y sont plus. Sans cela, retirer une zone d'un produit n'aurait aucun
        // effet sur les titres deja emis, et n'en aurait aucun SANS RIEN DIRE.
        $this->accessZones->applyTo($droit, $droit->getProduitRef(), $etablissement);

        // ⚠ UNE RE-PROJECTION NE ROUVRE PAS UN DROIT COUPÉ. Elle rafraîchit le crédit et les zones ; le
        // statut appartient à qui l'a coupé (abonnement résilié, impayé, pause). Le remettre à « valide »
        // rouvrait l'abonnement dont on ré-appairait le billet sur une nouvelle carte (mesuré le 07/10).
        if ($estNouveau) {
            $droit->setStatutProjection(StatutProjectionDroit::Valide);
        }
        $droit->setSynchroniseLe(new \DateTimeImmutable());

        $this->em->persist($droit);

        return $droit;
    }
}
