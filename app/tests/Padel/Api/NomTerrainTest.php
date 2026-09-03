<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Padel\Entity\TerrainPadel;
use App\Reservation\Entity\Ressource;
use App\Tests\Padel\PadelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE NOM D'UN TERRAIN — l'API n'en rendait aucun, et la création passait pour cassée.
 *
 * ── CE QUE MAXIME A SIGNALÉ, ET CE QUE C'ÉTAIT VRAIMENT ────────────────────────────────────────
 *
 * « Quand j'essaie de créer un terrain, ça ne marche pas ». J'ai d'abord cru à un refus du serveur,
 * et j'avais même une hypothèse détaillée — l'opération de création est la seule des quatre POST de
 * cette entité à ne pas déclarer `input: false`. **Elle était fausse** : le POST rend 201 et crée
 * bien le terrain, avec sa ressource.
 *
 * Le défaut était dans ce que l'API rend ensuite :
 *
 *     GET /api/padel_terrains
 *       ressource : "/api/reservation_ressources/8420ff5a-…"   (une IRI, pas un objet)
 *       libelle   : null                                        (le champ n'existait pas)
 *
 * `TerrainPadel` ne porte pas de libellé — il vit sur la `Ressource` que `CreerTerrainProcessor`
 * crée en cascade. L'écran lit `t.ressource?.libelle || t.libelle || ''`, donc la chaîne vide dans
 * tous les cas. **Un terrain créé s'affichait sans nom.** Vues de l'écran, « la création a échoué »
 * et « la création a réussi mais son résultat est invisible » se ressemblent beaucoup.
 *
 * ⚠ CE FICHIER GARDE LES DEUX MOITIÉS, parce que corriger l'une sans l'autre ne se verrait pas :
 * que le nom soit rendu, et qu'il ne soit pas devenu un second chemin d'écriture.
 */
final class NomTerrainTest extends PadelApiTestCase
{
    /**
     * **Le test qui compte : le nom traverse jusqu'à l'API.**
     *
     * Encadré par un témoin, parce qu'une assertion sur un libellé non vide passerait aussi bien
     * si l'API rendait le libellé d'un AUTRE terrain, ou une valeur par défaut.
     */
    public function testLeNomDuTerrainEstRenduParLApi(): void
    {
        [$client, $entete] = $this->adminSurA();

        $terrain = $this->entite(TerrainPadel::class, []);
        $attendu = $terrain->getRessource()?->getLibelle();

        self::assertNotNull($attendu, 'Le terrain de démonstration n a pas de ressource : ce test ne mesure rien.');
        self::assertNotSame('', trim($attendu), 'Sa ressource n a pas de libellé : ce test ne mesure rien.');

        $client->request('GET', '/api/padel_terrains/' . $terrain->getId(), $entete);

        self::assertResponseIsSuccessful();
        $vu = $client->getResponse()->toArray();

        self::assertArrayHasKey('libelle', $vu, 'L API ne rend aucun libellé : l écran ne peut pas nommer le terrain.');
        self::assertSame($attendu, $vu['libelle'], 'Le libellé rendu n est pas celui de la ressource du terrain.');
    }

    /**
     * **Et il apparaît dans la COLLECTION, qui est ce que l'écran lit vraiment.**
     *
     * L'écran Padel liste les terrains ; c'est là que l'absence se voyait. Un test qui ne
     * contrôlerait que la fiche unitaire laisserait passer un groupe de sérialisation posé sur la
     * mauvaise opération.
     */
    public function testLeNomApparaitAussiDansLaCollection(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/padel_terrains', $entete);
        self::assertResponseIsSuccessful();

        /** @var list<array<string, mixed>> $lignes */
        $lignes = $client->getResponse()->toArray()['member']
            ?? $client->getResponse()->toArray()['hydra:member'];

        self::assertNotEmpty($lignes, 'Aucun terrain listé : ce test ne mesure rien.');

        foreach ($lignes as $ligne) {
            self::assertArrayHasKey('libelle', $ligne);
            self::assertNotSame('', trim((string) $ligne['libelle']), sprintf(
                'Un terrain est listé sans nom (%s) : c est exactement le défaut signalé.',
                (string) ($ligne['@id'] ?? '?'),
            ));
        }
    }

    /**
     * ⚠ **LE NOM RESTE EN LECTURE SEULE, ET C'EST LA MOITIÉ QU'ON OUBLIE.**
     *
     * Le libellé appartient à la `Ressource` du socle. L'exposer en écriture ici donnerait DEUX
     * chemins pour renommer un terrain, dont un qui contourne le socle — et le jour où les deux
     * divergent, le planning réserve une ressource dont le nom n'est plus celui qu'on voit.
     *
     * Ce test le vérifie par le geste, pas par la lecture du code : un PATCH qui prétend renommer
     * ne doit rien changer.
     */
    public function testLeNomNeSeModifiePasParLeTerrain(): void
    {
        [$client, $entete] = $this->adminSurA();

        $terrain = $this->entite(TerrainPadel::class, []);
        $avant = $terrain->getRessource()?->getLibelle();
        self::assertNotNull($avant);

        // ⚠ L'IDENTIFIANT SE CAPTURE AVANT LE `clear()`, PAS APRES. Une entite detachee ne charge
        // plus ses relations : `$terrain->getRessource()` apres le vidage aurait leve, et le test
        // aurait accuse le correctif au lieu de sa propre mecanique.
        $idRessource = $terrain->getRessource()->getId();

        $client->request('PATCH', '/api/padel_terrains/' . $terrain->getId(), $this->entetePatch($entete) + [
            'json' => ['libelle' => 'Nom pose par le mauvais chemin'],
        ]);

        // Peu importe que l'API accepte ou refuse la requête : ce qui compte est que le nom n'ait
        // pas bougé. Une écriture silencieusement ignorée et un refus produisent le même bon état.
        $em = $this->em();
        $em->clear();
        $relu = $em->getRepository(Ressource::class)->find($idRessource);

        self::assertNotNull($relu);
        self::assertSame($avant, $relu->getLibelle(), 'Le terrain est devenu un second chemin pour renommer sa ressource.');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
