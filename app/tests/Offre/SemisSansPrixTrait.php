<?php

declare(strict_types=1);

namespace App\Tests\Offre;

use App\Offre\Entity\Produit;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\PublicationGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AUCUN SEMIS NE DOIT PUBLIER UN PRODUIT SANS PRIX.
 *
 * ── POURQUOI CE FILET EXISTE ────────────────────────────────────────────────────────────────────
 *
 * `PublicationGuard` interdit de publier sans prix, et `PriceGridProcessor` interdit désormais de
 * retirer le dernier. Les deux gardent l'API — **et les fixtures écrivent `setStatut(Publie)` en dur
 * sur l'entité, donc passent à travers le mur.** Le 31/08, trois produits publiés du musée
 * (`PRD-AUDIOGUIDE`, `PRD-EXPO-EGYPTE`, `PRD-PASS-MUSEE`) n'avaient aucune grille : ils
 * s'affichaient sur la boutique publique, ajoutables au panier, avec rien à calculer au paiement.
 *
 * Une donnée de démonstration qui contredit la règle ne reste pas une donnée de démonstration : on
 * la prend pour un modèle. C'est déjà arrivé deux fois sur ce dépôt (le cadenas, la place limitée).
 *
 * ── CE QUE CE FILET NE VÉRIFIE PAS, ET IL FAUT LE SAVOIR ────────────────────────────────────────
 *
 * ⚠ Il ne contrôle QUE le prix, pas les quatre autres prérequis de `PublicationGuard`. Le prérequis
 * « site » est aujourd'hui violé par la totalité des produits publiés de la préprod — et
 * volontairement : `PerimetreProduitExtension` traite « aucun établissement » comme « socle, partagé
 * par tous ». Les deux règles se contredisent, et l'arbitrage revient à Maxime ; l'ajouter ici
 * rendrait le filet rouge pour une raison qui n'est pas la sienne.
 *
 * ⚠ Il ne voit que les fixtures chargées par le harnais qui l'utilise. D'où deux accrochages —
 * musée et boutique, les deux seuls harnais à neuf fixtures. Un module dont les fixtures ne sont
 * chargées ni par l'un ni par l'autre n'est pas couvert.
 */
trait SemisSansPrixTrait
{
    public function testAucunProduitSemeNEstPublieSansPrix(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var PublicationGuard $garde */
        $garde = static::getContainer()->get(PublicationGuard::class);

        $publies = $em->getRepository(Produit::class)->findBy(['statut' => StatutProduit::Publie]);

        // Témoin positif : sans lui, un semis qui ne publierait RIEN rendrait ce test vert en
        // n'ayant rien mesuré — la forme exacte du zéro qui ment.
        self::assertNotEmpty($publies, 'Le semis doit publier au moins un produit, sinon ce test ne mesure rien.');

        $sansPrix = [];
        foreach ($publies as $produit) {
            // On interroge la garde elle-même plutôt que de redire ce qu'est « un prix valide » :
            // une règle recopiée diverge au premier correctif.
            if (\in_array('prix', $garde->prerequisManquants($produit), true)) {
                $sansPrix[] = $produit->getCode();
            }
        }

        sort($sansPrix);
        self::assertSame([], $sansPrix, sprintf(
            'Ces produits sont publiés sans aucun prix valide : %s. Un produit publié sans tarif '
            .'s\'affiche en vente et n\'a rien à facturer — la fixture doit lui poser une grille.',
            implode(', ', $sansPrix)
        ));
    }
}
