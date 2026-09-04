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

    /**
     * ⚠ `$produitRef` EST OBLIGATOIRE DEPUIS LE 04/09, ET NON NULLABLE. Arbitrage de Maxime.
     *
     * Cette methode faisait `setProduit($produitRef ?? Uuid::v4())` : sans produit, la ligne
     * designait un produit qui n'existe pas — donc sans categorie comptable, donc absente de la
     * ventilation — et `LineLabelStamper` laissait le libelle nul, ce qu'il documente lui-meme.
     *
     * Un type non nullable plutot qu'un `throw` : le `throw` se declenche a l'execution, chez le
     * premier malchanceux. Le type oblige CHAQUE appelant a decider quoi dire quand il n'a pas de
     * produit, et le prochain appelant ecrit ne pourra pas passer `null` sans le voir.
     */
    public function creerVente(
        SessionCaisse $session,
        string $montant,
        ?Uuid $clientRef,
        string $libelle,
        Uuid $produitRef,
        ?Uuid $activiteRef = null,
        ?Uuid $ressourceRef = null,
        ?Uuid $typeTarifRef = null,
    ): Vente
    {
        $vente = new Vente();
        $vente->setSession($session)
            ->setEtablissement($session->getEtablissement())
            ->setNumero($this->generateurNumero->numeroVente($session));
        if ($clientRef !== null) {
            $vente->setClient($clientRef);
        }

        $ligne = new LigneVente();
        $ligne->setProduit($produitRef);
        // ⚠ `null` EST UNE RÉPONSE, ET C'EST NEUF (§8.11). Cette ligne portait
        // `$typeTarifRef ?? Uuid::v4()` : sans type de tarif, elle en inventait un, qui ne désigne
        // rien. Maxime a tranché de rendre le champ nullable, pour que `null` dise « personne n'a
        // pu en fournir un » au lieu de prétendre.
        //
        // Ce que le type de tarif commande, mesuré le 04/09 : la comptabilité ne le lit PAS ; son
        // seul lecteur sur une ligne est `LineLabelStamper`, qui le résout pour figer son LIBELLÉ.
        // Un identifiant inventé ne résolvait rien — le ticket sortait donc déjà sans « Plein
        // tarif » ni « Membre ». Avec `null`, il sort exactement pareil : le rendu ne change pas,
        // seul le modèle cesse de mentir.
        //
        // Qui en fournit un : le padel, depuis `ParametragePadel` (membre / non-membre). Une
        // réservation générique n'en a AUCUN — `Activite` n'en porte pas, et son docblock dit que
        // la chaîne de tarification est hors périmètre du lot socle.
        $ligne->setTypeTarif($typeTarifRef);
        $ligne->setQuantite(1);
        $ligne->setPrixUnitaire($montant);
        $ligne->setPrixForce(true);
        $ligne->setMontantLigne($montant);
        $ligne->setNote($libelle);
        // ⚠ D'OÙ VIENT LA RECETTE (§8.12). Sans ces deux références, rien sur la ligne ne disait de
        // quelle prestation ni de quel équipement elle venait : la comptabilité voyait « Locations »
        // et personne ne pouvait répondre à « combien le padel a-t-il rapporté ».
        $ligne->setActivite($activiteRef);
        $ligne->setRessource($ressourceRef);

        $vente->addLigne($ligne);
        $this->calculateur->recalculerVente($vente);

        $this->em->persist($vente);
        $this->em->persist($ligne);
        $this->em->flush();

        return $vente;
    }
}
