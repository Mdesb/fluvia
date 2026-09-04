<?php

declare(strict_types=1);

namespace App\Vente\Doctrine;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Vente\Entity\LigneVente;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Grave sur la ligne, à sa création, **le nom que portaient le produit et le tarif ce jour-là**.
 *
 * **Pourquoi ça n'est pas fait par les appelants.** Six endroits du dépôt construisent une
 * `LigneVente` — caisse, boutique, abonnement en ligne, réservation, synchronisation hors ligne, jeu
 * de données — et **aucun ne dispose de l'entité `Produit`** : la ligne ne porte qu'un `Uuid` nu, par
 * la convention des références libres. Leur demander de résoudre le produit pour en copier le nom
 * aurait fait reposer le contenu du ticket sur la vigilance de six appelants, dont quatre hors de mon
 * périmètre. C'est le même raisonnement que `Vente::setSession()`, qui pose le point de vente : un
 * invariant qui dépend d'un appel qu'on peut oublier n'est pas un invariant.
 *
 * **Pourquoi à la création et pas à la lecture.** C'est tout l'objet : à la lecture, le produit peut
 * avoir été renommé, retarifé, ou supprimé. Un ticket doit dire ce qui a été vendu **le jour où on
 * l'a vendu**.
 *
 * **Quand le produit reste introuvable, le libellé reste nul** — et c'est délibéré. Certaines lignes
 * portent une référence qui ne désigne aucun `Produit` du catalogue (`VenteReservationHandler` pose
 * un identifiant arbitraire quand la réservation n'a pas de produit). Écrire « Produit » ou un
 * libellé de remplacement donnerait à un ticket l'apparence d'un document complet ; un champ nul dit
 * qu'on ne sait pas, ce qui est la vérité et se corrige.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class LineLabelStamper
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $ligne = $args->getObject();
        if (!$ligne instanceof LigneVente) {
            return;
        }

        $em = $args->getObjectManager();

        if ($ligne->getLibelleProduit() === null) {
            $produit = $em->getRepository(Produit::class)->find($ligne->getProduit());
            if ($produit instanceof Produit) {
                $ligne->setLibelleProduit($produit->getLibelle());
            }
        }

        // ⚠ `find(null)` N'EST PAS UNE RECHERCHE. Depuis que `typeTarif` est nullable (§8.11), une
        // ligne peut légitimement n'en porter aucun — une réservation générique, dont l'activité ne
        // déclare pas de type de tarif. On le dit AVANT d'interroger, pas après.
        $typeTarif = $ligne->getTypeTarif();
        if ($typeTarif !== null && $ligne->getLibelleTypeTarif() === null) {
            $tarif = $em->getRepository(TypeTarif::class)->find($typeTarif);
            if ($tarif instanceof TypeTarif) {
                $ligne->setLibelleTypeTarif($tarif->getNom());
            }
        }
    }
}
