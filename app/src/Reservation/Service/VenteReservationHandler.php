<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Caisse\Entity\SessionCaisse;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Déclenche une Vente M2 réelle depuis une réservation (vente à l'unité RG-M5-02, facturation
 * no-show RG-M5-09) : ce module **déclenche**, M2 **encaisse et trace** (§8 spec). Construction
 * directe d'une ligne à prix forcé (sans passer par `AjoutLigneHandler`/`ResolveurPrix`, hors
 * périmètre socle : pas de résolution de grille tarifaire M1 complète dans ce lot — divergence
 * documentée, cf. `Activite::$tarifReferenceMontant`).
 */
final class VenteReservationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenerateurNumero $generateurNumero,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    public function creerVente(SessionCaisse $session, string $montant, ?Uuid $clientRef, string $libelle, ?Uuid $produitRef = null): Vente
    {
        $vente = new Vente();
        $vente->setSession($session)
            ->setEtablissement($session->getEtablissement())
            ->setNumero($this->generateurNumero->numeroVente($session));
        if ($clientRef !== null) {
            $vente->setClient($clientRef);
        }

        $ligne = new LigneVente();
        $ligne->setProduit($produitRef ?? Uuid::v4());
        $ligne->setTypeTarif(Uuid::v4());
        $ligne->setQuantite(1);
        $ligne->setPrixUnitaire($montant);
        $ligne->setPrixForce(true);
        $ligne->setMontantLigne($montant);
        $ligne->setNote($libelle);

        $vente->addLigne($ligne);
        $this->calculateur->recalculerVente($vente);

        $this->em->persist($vente);
        $this->em->persist($ligne);
        $this->em->flush();

        return $vente;
    }
}
