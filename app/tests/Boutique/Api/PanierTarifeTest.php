<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * ⚠ LE PANIER AFFICHAIT SES PRIX, PUIS LES PERDAIT AU PREMIER GESTE DU CLIENT.
 *
 * Constaté à l'écran, boutique publique, panier de deux audioguides :
 *
 *     Audioguide   4,00 € l'unité   −  2  +   8,00 €     Total 8,00 €
 *     (un clic sur « − »)
 *     Audioguide                    −  1  +              « Le montant total sera calculé
 *                                                          et affiché à l'étape de paiement. »
 *
 * Plus aucun montant, et le bouton « Passer la commande » juste à côté. Le repli se lit comme une
 * politique commerciale et non comme un raté : c'est ce qui rendait le défaut durable.
 *
 * La cause : `GET /boutique/paniers/{id}` passe par `PanierAvecTotalProvider`, qui appelle
 * `calculer()`. Les mutations rendent le panier par un **processeur**, et aucun des sept
 * n'enrichissait. Le frontal garde cette réponse en état, donc les prix disparaissaient jusqu'au
 * prochain rechargement.
 */
final class PanierTarifeTest extends BoutiqueApiTestCase
{
    /**
     * ⚠ LA MOITIÉ QUI COMPTE EST LA PREMIÈRE : ON PROUVE D'ABORD QUE LE PRIX EXISTE.
     *
     * Un panier peut n'avoir aucun prix pour quantité de raisons — produit sans grille, canal non
     * publié, date hors validité. Sans ce témoin, l'assertion d'après resterait verte le jour où le
     * catalogue cesse de résoudre un tarif, et elle affirmerait une protection qu'elle ne mesure pas.
     */
    public function testUneQuantiteModifieeNeFaitPasDisparaitreLesPrix(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = ['headers' => [PanierProprietaireGuard::HEADER => $jeton]];

        $avecLigne = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', $entete + [
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 2],
        ])->toArray();

        // ─── Témoin : le prix EXISTE sur la réponse de la mutation qui l'ajoute ────────────────
        self::assertNotNull(
            $avecLigne['total'] ?? null,
            'Le produit de démonstration doit avoir un tarif en ligne, sinon la suite ne mesure rien.',
        );
        self::assertNotNull(
            $avecLigne['lignes'][0]['prixUnitaire'] ?? null,
            'Sans prix unitaire ici, l’assertion suivante serait verte pour une raison sans rapport.',
        );
        $totalPourDeux = $avecLigne['total'];
        $idLigne = $avecLigne['lignes'][0]['id'];

        // ─── Et il SURVIT au geste qui l'effaçait ─────────────────────────────────────────────
        $apres = $client->request(
            'POST',
            '/api/boutique/paniers/' . $panierId . '/lignes/' . $idLigne . '/quantite',
            $entete + ['json' => ['quantite' => 1]],
        )->toArray();

        self::assertNotNull(
            $apres['total'] ?? null,
            'Le panier a perdu son total en changeant de quantité : l’écran affiche « le montant sera '
            .'calculé à l’étape de paiement » juste à côté du bouton de commande.',
        );
        self::assertNotNull($apres['lignes'][0]['prixUnitaire'] ?? null);
        self::assertNotSame(
            $totalPourDeux,
            $apres['total'],
            'Le total doit suivre la quantité — un total figé serait un mensonge plus discret que son absence.',
        );
    }

    /**
     * TOUT PROCESSEUR QUI REND UN PANIER LE TARIFE — Y COMPRIS CEUX QUI N'EXISTENT PAS ENCORE.
     *
     * ⚠ CE DÉFAUT NE LÈVE RIEN. Un processeur qui oublie l'appel ne casse aucun test d'API : il rend
     * un panier parfaitement valide, simplement sans prix, et c'est l'écran qui ment. Les sept
     * l'avaient oublié en même temps, ce qui dit assez qu'un commentaire n'y suffirait pas.
     */
    public function testChaqueProcesseurQuiRendUnPanierLeTarife(): void
    {
        $racine = \dirname(__DIR__, 3) . '/src/Boutique/State';
        $rendentUnPanier = [];
        $sansTarification = [];

        foreach (glob($racine . '/*.php') ?: [] as $fichier) {
            $code = (string) file_get_contents($fichier);
            if (!str_contains($code, '): PanierEnLigne')) {
                continue;
            }
            $rendentUnPanier[] = basename($fichier);
            if (!str_contains($code, '->calculer(')) {
                $sansTarification[] = basename($fichier);
            }
        }

        // ⚠ TÉMOIN POSITIF. Un chemin faux, une extension oubliée, et la liste des manquants reste
        // vide pour une raison qui n'a aucun rapport avec ce qu'on mesure.
        self::assertGreaterThanOrEqual(
            7,
            \count($rendentUnPanier),
            'Le balayage n’a pas trouvé les processeurs connus : c’est lui qu’il faut corriger, pas le code.',
        );

        self::assertSame(
            [],
            $sansTarification,
            'Ces processeurs rendent un panier sans le tarifer. L’écran affichera un panier sans aucun '
            .'montant jusqu’au prochain rechargement, et rien ne lèvera.',
        );
    }
}
