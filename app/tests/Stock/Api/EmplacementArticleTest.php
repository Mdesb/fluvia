<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Stock\StockApiTestCase;

/**
 * OÙ L'ARTICLE SE TROUVE — le rayonnage PHYSIQUE de R3.
 *
 * Maxime a distingué les deux rayonnages lui-même : « il y a une distinction à faire entre un
 * rayonnage physique et un rayonnage sur la caisse qui est purement un système d'affichage ».
 *
 *     rayon de CAISSE   une catégorie d'axe `rayon`, PLUSIEURS par produit, qui range un écran
 *     ce champ          un LIEU, UN seul, qui répond à « où vais-je le chercher »
 *
 * ⚠ ET CE TEST EXISTE PARCE QU'UNE COLLECTION DE CE DÉPÔT A DÉJÀ ACCEPTÉ UNE ÉCRITURE SANS RIEN
 * ENREGISTRER. `Produit::$categories` répondait 200 et n'écrivait pas (`267a735b`). Une propriété
 * neuve dans un groupe d'écriture se vérifie désormais, elle ne se suppose pas.
 */
final class EmplacementArticleTest extends StockApiTestCase
{
    public function testLEmplacementSEcritEtSeRelit(): void
    {
        [$http, $entete] = $this->adminSurA();

        $cree = $http->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corpsArticle('Réserve · étagère B'),
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame(
            'Réserve · étagère B',
            $cree['storageLocation'] ?? null,
            'le lieu doit revenir tel qu’il a été saisi — un 200 ne prouve pas une écriture',
        );

        $relu = $http->request('GET', '/api/article_stocks/' . basename((string) $cree['@id']), $entete)
            ->toArray();
        self::assertSame('Réserve · étagère B', $relu['storageLocation'] ?? null, 'et survivre à la relecture');
    }

    /**
     * ⚠ UNE CHAÎNE VIDE N'EST PAS UN LIEU.
     *
     * Un champ vidé par l'exploitant arrive comme `''`. Le laisser passer afficherait un
     * emplacement blanc dans la colonne « Rangé » — indiscernable d'un article dont on connaît la
     * place mais dont le libellé serait vide. `null` dit « on ne sait pas », et c'est ce qu'on veut
     * dire.
     */
    public function testUneChaineVideDevientAucunLieu(): void
    {
        [$http, $entete] = $this->adminSurA();

        $cree = $http->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corpsArticle('Réserve'),
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('Réserve', $cree['storageLocation'] ?? null, 'témoin : le lieu est bien posé');

        // ⚠ `+` ENTRE TABLEAUX NE REMPLACE PAS LES CLES EXISTANTES. `$entete` porte deja
        // `headers` : y ajouter le mien par `+` le laissait tomber en silence, et le PATCH partait
        // en `application/ld+json`, que l'API refuse. On fusionne la sous-clef a la main.
        $entetePatch = $entete;
        $entetePatch['headers']['Content-Type'] = 'application/merge-patch+json';

        $vide = $http->request('PATCH', '/api/article_stocks/' . basename((string) $cree['@id']), $entetePatch + [
            'json' => ['storageLocation' => '   '],
        ])->toArray();

        // ⚠ API PLATFORM OMET LES VALEURS NULLES DE SA SORTIE : quand le lieu est effacé, la clé
        // DISPARAIT au lieu de valoir `null`. Absent et nul disent donc ici la même chose, et
        // `?? null` est la bonne lecture — c'est le seul endroit de ce fichier où elle l'est.
        //
        // (Mon premier jet écrivait `?? 'pas-nul'`, qui se déclenche AUSSI sur `null` et faisait
        // échouer l'assertion sur le comportement juste. Deuxième fois aujourd'hui.)
        self::assertNull(
            $vide['storageLocation'] ?? null,
            'des espaces ne sont pas un lieu : le champ doit revenir à « on ne sait pas »',
        );

        // Le témoin : ce n'est pas la réponse du PATCH qui se trompe, la relecture le confirme.
        $relu = $http->request('GET', '/api/article_stocks/' . basename((string) $cree['@id']), $entete)
            ->toArray();
        self::assertNull($relu['storageLocation'] ?? null, 'et le lieu reste effacé après relecture');
    }

    /** @return array<string, string> */
    private function corpsArticle(string $emplacement): array
    {
        return [
            'etablissement' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
            'codeEAN' => '96385074',
            'libelle' => 'Mug boutique',
            'unite' => 'piece',
            'prixAchatHT' => '4.0000',
            'tauxTvaAchat' => '20.00',
            'seuilMin' => '5.000',
            'seuilMax' => '50.000',
            'storageLocation' => $emplacement,
        ];
    }
}
