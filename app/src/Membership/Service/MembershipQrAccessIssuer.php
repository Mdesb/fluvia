<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport as AccesTypeSupport;
use App\Acces\Service\AppairageHandler;
use App\Acces\Service\ProductAccessZoneResolver;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Offre\Entity\Formule;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Vente\Enum\TypeSupport as VenteTypeSupport;
use App\Vente\Service\GenerateurCodeSupport;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE BILLET D'ACCÈS D'UN ABONNEMENT, ÉMIS À LA SOUSCRIPTION.
 *
 * Jusqu'ici la souscription ouvrait un `StatutAccesFitness` SANS support (`droitAcces = null`) :
 * aucun billet, et la porte ne s'ouvrait pas (le terminal lit un SUPPORT, jamais l'abonnement).
 * On construit donc, comme `Personnel\EmissionBadgeStaffHandler` pour un badge staff, un
 * `DroitAcces` (`sourceType = Abonnement`) et on lui appaire un support QR signé — réutilisant
 * `App\Acces\Service\AppairageHandler` et `App\Vente\Service\GenerateurCodeSupport` (partagé, déjà
 * employé par Personnel et Accès) SANS modifier `App\Acces`.
 *
 * ⚠ LES ZONES VIENNENT DU PRODUIT (D87). Un `DroitAcces` d'abonnement N'EST PAS exempté de zone :
 * sans zone déclarée, le QR se valide mais n'ouvre AUCUNE porte. On applique donc les zones du
 * produit qui porte la formule, exactement comme un billet (`ProductAccessZoneResolver`). Si le
 * produit n'en déclare pas, le support existe mais n'ouvre rien — c'est une donnée à renseigner sur
 * la fiche produit, pas un défaut de ce code.
 *
 * ⚠ ÉTABLISSEMENT EN DIRECT (cloisonnement) : on le pose depuis le paramètre, jamais en nullsafe.
 */
final class MembershipQrAccessIssuer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppairageHandler $appairageHandler,
        private readonly GenerateurCodeSupport $generateurCode,
        private readonly ProductAccessZoneResolver $accessZones,
    ) {
    }

    /**
     * Émet le droit d'accès + le support QR de l'abonné, les rattache au `StatutAccesFitness`, et
     * renvoie l'identifiant signé du support (le code du billet, à afficher/imprimer).
     */
    public function issue(
        Membership $abonnement,
        Formule $formule,
        Etablissement $etablissement,
        StatutAccesFitness $statutAcces,
    ): string {
        // ⚠ PAS DE FORMULE→PRODUIT : la relation vit sur le Produit (côté propriétaire). On retrouve
        // donc le produit par sa formule, pour en résoudre les zones (même rapprochement que la fiche).
        $produit = $this->em->getRepository(Produit::class)->findOneBy(['formule' => $formule]);
        $produitRef = $produit?->getId();

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::Abonnement)
            ->setEtablissement($etablissement)
            ->setProduitRef($produitRef)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setSynchroniseLe(new \DateTimeImmutable());
        $this->em->persist($droit);

        // D87 : sans zones, le droit n'ouvre rien (l'abonnement n'est pas exempté). On applique
        // celles du produit — comme un billet. Vide si le produit n'en déclare pas (à renseigner).
        $this->accessZones->applyTo($droit, $produitRef, $etablissement);

        // Le code du support est SIGNÉ par le même générateur que le terminal vérifie
        // (`VerdictBilletHandler`) : ne jamais forger une chaîne à la main, elle serait refusée.
        $identifiant = $this->generateurCode->genererPourType(VenteTypeSupport::Qr);

        $appairage = $this->appairageHandler->appairer(
            $identifiant,
            AccesTypeSupport::Qr,
            $droit,
            ModeAppairage::Caisse,
            $etablissement,
            null,
        );

        $statutAcces->setDroitAcces($droit)
            ->setSupportIdentifiant($appairage->getSupport()?->getIdentifiant());

        return $identifiant;
    }
}
