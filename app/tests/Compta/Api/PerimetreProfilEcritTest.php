<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Tests\Compta\ComptaApiTestCase;

/**
 * LE PÉRIMÈTRE D'UN PROFIL S'ÉCRIT VRAIMENT — et il ne s'écrivait pas.
 *
 * `ProfilExploitant::$etablissementsRattaches` est dans `profil:write`, et `PerimetreFinanceExtension`
 * comme `PerimetreFacturationExtension` le lisent pour décider ce qu'un profil voit. Accorder ou
 * retirer un établissement répondait **200** et ne changeait **rien**.
 *
 * ── LA CAUSE, MESURÉE ───────────────────────────────────────────────────────────────────────────
 *
 * `PropertyAccessor` cherche un couple `add`/`remove` bâti sur le singulier ANGLAIS de la propriété :
 *
 *     l'entité offrait          Symfony cherchait
 *     addEtablissementRattache  addEtablissementsRattache   (et aucun remover du tout)
 *
 * L'auteur avait écrit le singulier français correct, sur les deux mots. Ils ne se rencontrent
 * jamais, et API Platform saute le champ **en silence**.
 *
 * ⚠ ET LE COUPLE EST EXIGÉ EN ENTIER. Même un adder correctement nommé n'aurait pas suffi : il n'y
 * avait aucun `removeEtablissementRattache`.
 *
 * ── CE QUE CE TEST PROUVE QUE LE GARDE-FOU N°47 NE PROUVE PAS ───────────────────────────────────
 *
 * Le garde-fou vérifie la FORME de l'entité : un setter existe. Il ne prouve pas qu'API Platform
 * l'APPELLE — c'est une propriété du sérialiseur, pas du dépôt. Les deux moitiés se prouvent
 * séparément, et celle-ci ne se prouve qu'en exécutant.
 *
 * ── ⚠ POURQUOI UN SEUL TEST, QUI VIDE AVANT DE REMPLIR ──────────────────────────────────────────
 *
 * Mon premier jet séparait « rattacher » et « retirer ». En cassant le correctif pour vérifier que
 * le filet attrape, **seul le retrait est tombé** : la fixture rattache DÉJÀ l'établissement A au
 * profil, donc « rattacher A puis relire A » était vert avec ou sans le défaut. Un test qui ne peut
 * pas échouer n'est pas un filet, c'est du décor.
 *
 * D'où cet aller-retour : chaque étape change réellement l'état, et chaque assertion peut tomber.
 */
final class PerimetreProfilEcritTest extends ComptaApiTestCase
{
    public function testLePerimetreSeVideEtSeRemplitVraiment(): void
    {
        [$client, $entete, $idEtablissement] = $this->adminSurA();
        $idProfil = $this->idProfilExploitant();

        // ⚠ TÉMOIN DE DÉPART : la fixture rattache bien quelque chose. S'il n'y avait rien à
        // retirer, l'étape suivante ne prouverait rien.
        self::assertNotSame(
            [],
            $this->rattachesDe($client, $entete, $idProfil),
            'témoin : le profil part avec un périmètre non vide',
        );

        // 1. Vider — c'est CE geste que l'ancien code ne pouvait pas faire : aucun remover.
        $this->ecrire($client, $entete, $idProfil, []);
        self::assertSame(
            [],
            $this->rattachesDe($client, $entete, $idProfil),
            'une liste vide doit vider le périmètre — sans remover, l’ancien code en était incapable',
        );

        // 2. Remplir — et comme on part de vide, l'ajout change réellement quelque chose.
        $this->ecrire($client, $entete, $idProfil, ['/api/etablissements/' . $idEtablissement]);
        self::assertSame(
            [$idEtablissement],
            $this->rattachesDe($client, $entete, $idProfil),
            'le rattachement doit survivre à la relecture — un 200 ne prouve aucune écriture',
        );

        // 3. Re-vider — un aller sans retour laisserait passer un setter qui n'ajoute qu'en cumul.
        $this->ecrire($client, $entete, $idProfil, []);
        self::assertSame(
            [],
            $this->rattachesDe($client, $entete, $idProfil),
            'et le périmètre doit pouvoir se vider une seconde fois',
        );
    }

    /**
     * Écrit la liste des établissements rattachés.
     *
     * @param array<string, mixed> $entete
     * @param list<string>         $iris
     */
    private function ecrire(object $client, array $entete, string $idProfil, array $iris): void
    {
        // ⚠ `+` ENTRE TABLEAUX NE REMPLACE PAS LES CLÉS EXISTANTES : `$entete` porte déjà `headers`,
        // donc y ajouter le mien par `+` le laisserait tomber en silence et le PATCH partirait en
        // `application/ld+json`, que l'API refuse. On fusionne la sous-clef à la main.
        //
        // ⚠ Et l'en-tête est construit ICI parce que le harnais Compta n'a pas d'`entetePatch()` —
        // celui de Padel en a un. Vérifié avant d'écrire, plutôt qu'emprunté de mémoire.
        $entete['headers']['Content-Type'] = 'application/merge-patch+json';

        $client->request('PATCH', '/api/profil_exploitants/' . $idProfil, $entete + [
            'json' => ['etablissementsRattaches' => $iris],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Les identifiants des établissements rattachés, tels que l'API les rend.
     *
     * Une relation arrive tantôt en objet (`{ '@id': … }`), tantôt en IRI nue selon les groupes de
     * sérialisation. On compare des identifiants, jamais des formes.
     *
     * @param array<string, mixed> $entete
     *
     * @return list<string>
     */
    private function rattachesDe(object $client, array $entete, string $idProfil): array
    {
        $client->request('GET', '/api/profil_exploitants/' . $idProfil, $entete);
        self::assertResponseIsSuccessful();
        $relu = $client->getResponse()->toArray();

        $ids = array_map(
            static fn (mixed $e): string => basename(\is_array($e) ? ($e['@id'] ?? '') : (string) $e),
            $relu['etablissementsRattaches'] ?? [],
        );
        sort($ids);

        return array_values($ids);
    }
}
