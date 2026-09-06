<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Securite\Security\CustomerAccountPathListener;
use PHPUnit\Framework\TestCase;

/**
 * LA LISTE BLANCHE DES CLIENTS FINALS COUVRE CE QUE LE FRONTAL PUBLIC APPELLE — et le dit si ça change.
 *
 * `CustomerAccountPathListener::ALLOWED_PATHS` est une liste tenue à la main. Une liste tenue à la main
 * ment le jour où quelqu'un ajoute un appel à `boutiqueClient.js` sans y penser : l'espace client
 * recevrait 403 sur une route neuve, et personne ne saurait pourquoi. Ce test lit le fichier du frontal,
 * en extrait chaque chemin `/api/…`, et exige qu'il soit couvert. Il tombe AVANT l'écran, pas après.
 *
 * Et il refuse de conclure sur un fichier vide : un zéro n'est une preuve que si l'on a vu quelque chose.
 */
final class CustomerAllowlistCoversPublicShopTest extends TestCase
{
    private const CLIENT_JS = __DIR__ . '/../../../../frontend/src/public/api/boutiqueClient.js';

    public function testChaqueCheminAppeleParLaBoutiquePubliqueEstDansLaListeBlanche(): void
    {
        self::assertFileExists(self::CLIENT_JS, 'le client HTTP de la boutique publique a bougé : mettre à jour ce test');
        $source = (string) file_get_contents(self::CLIENT_JS);

        preg_match_all('#[`\'"](/api/[a-zA-Z0-9_/${}.-]*)#', $source, $m);
        $chemins = array_values(array_unique($m[1]));
        self::assertNotEmpty($chemins, 'aucun chemin lu : le test ne prouverait rien');

        $horsListe = [];
        foreach ($chemins as $chemin) {
            // Les segments dynamiques (`${id}`) valent n'importe quoi : on les remplace par un segment neutre.
            $concret = (string) preg_replace('/\$\{[^}]*\}/', 'x', $chemin);
            if (preg_match(CustomerAccountPathListener::ALLOWED_PATHS, $concret) !== 1) {
                $horsListe[] = $chemin;
            }
        }

        self::assertSame([], $horsListe, sprintf(
            "Le frontal public appelle des chemins qu'un client final ne peut pas atteindre : %s.\n"
            . 'Soit ces appels ne sont faits qu\'avec le jeton STAFF (alors ils n\'ont rien à faire dans boutiqueClient.js), '
            . 'soit `CustomerAccountPathListener::ALLOWED_PATHS` doit les couvrir — en assumant ce qu\'on ouvre.',
            implode(', ', $horsListe),
        ));
    }

    /** Le témoin négatif : un chemin du back-office n'est PAS couvert — sinon la liste ne filtrerait rien. */
    public function testUnCheminDuBackOfficeNEstPasCouvert(): void
    {
        self::assertSame(0, preg_match(CustomerAccountPathListener::ALLOWED_PATHS, '/api/etablissements'));
        self::assertSame(0, preg_match(CustomerAccountPathListener::ALLOWED_PATHS, '/me'));
        self::assertSame(0, preg_match(CustomerAccountPathListener::ALLOWED_PATHS, '/api/boutiquex'), 'le préfixe se ferme par une barre');
    }
}
