<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Securite\SecuriteApiTestCase;

/**
 * « SUIS-JE CHEZ MOI, OU CHEZ UN CLIENT ? »
 *
 * L'accès d'assistance ouvre le site d'un client DANS LA MÊME APPLICATION, avec les mêmes écrans.
 * Un employé de l'éditeur y écrira une note, y lira des chiffres. Si rien ne l'avertit, il croira
 * être chez lui — et ce n'est pas une erreur qu'il commettra une fois : c'est une erreur qu'il
 * commettra tant que rien ne le détrompe.
 *
 * `/me` porte donc `estEditeur`, calculé par `EditorTenantResolver::isEditor()` sur l'établissement
 * ACTIF — pas sur le compte. Le même utilisateur est éditeur chez lui et ne l'est pas chez un
 * client : la réponse change avec l'en-tête `X-Etablissement`, et c'est exactement ce qu'on veut.
 *
 * ── CE QUE CE TEST GARDE VRAIMENT ───────────────────────────────────────────────────────────────
 *
 * **La fermeture par défaut.** `EDITOR_TENANT_ID` n'est pas renseignée dans l'environnement de test
 * — comme sur n'importe quel déploiement où l'éditeur n'est pas encore désigné. La bonne réponse
 * est alors « personne n'est l'éditeur », et non « tout le monde l'est ». `isEditor()` ne lève pas
 * et rend `false` ; ce test l'exige, parce qu'une désignation absente est justement le moment où un
 * repli permissif passerait inaperçu.
 *
 * ⚠ On assure aussi que la clé EXISTE. Sans cette assertion, un `estEditeur` disparu de la réponse
 * rendrait `null` côté écran — donc ni `true` ni `false`, et la bannière d'avertissement, écrite en
 * `=== false`, ne s'afficherait plus jamais. Le garde-fou tomberait en silence, exactement comme la
 * chose qu'il garde.
 */
final class EstEditeurTest extends SecuriteApiTestCase
{
    public function testLeProfilDitSiLEtablissementActifEstCeluiDeLEditeur(): void
    {
        [$client, $entete] = $this->adminSurA();

        $profil = $client->request('GET', '/me', $entete)->toArray();
        self::assertResponseIsSuccessful();

        self::assertArrayHasKey(
            'estEditeur',
            $profil,
            'Sans cette clé, l’écran ne reçoit ni vrai ni faux : la bannière « vous êtes chez un client » ne s’affiche plus jamais.',
        );
        self::assertIsBool($profil['estEditeur']);
        self::assertFalse(
            $profil['estEditeur'],
            'Aucun éditeur n’est désigné dans cet environnement : la réponse doit être « personne », jamais « tout le monde ».',
        );
    }

    /**
     * ⚠ LE CAS POSITIF, SANS LEQUEL LES DEUX AUTRES NE GARDENT RIEN.
     *
     * Un booléen qui rend TOUJOURS `false` passe les assertions précédentes : la bannière ne
     * s'afficherait jamais à tort — ni jamais à raison. `return false;` serait une implémentation
     * valide de tout ce qui précède. Il faut donc voir la réponse basculer.
     *
     * La désignation vient du déploiement, alors le test la pose comme le ferait un déploiement :
     * il cherche l'établissement éditeur par son nom et renseigne `EDITOR_TENANT_ID`. L'identifiant
     * est engendré à la création — pas de setter sur `Etablissement`, et il ne doit pas y en avoir
     * sur l'entité pivot du cloisonnement.
     */
    public function testSurLEtablissementEditeurLaReponseBascule(): void
    {
        $idEditeur = $this->idEtablissement(SocleFixtures::ETAB_EDITEUR_NOM);

        // Un noyau déjà démarré a pu résoudre la variable et la garder en cache : sans ce
        // redémarrage, le résultat dépendrait de ce qui a tourné avant — une dépendance qui se
        // découvre trois semaines plus tard, sur une autre machine.
        self::ensureKernelShutdown();
        $_ENV['EDITOR_TENANT_ID'] = $idEditeur;
        $_SERVER['EDITOR_TENANT_ID'] = $idEditeur;
        putenv('EDITOR_TENANT_ID=' . $idEditeur);

        try {
            $client = static::createClient();
            $jeton = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

            $profil = $client->request('GET', '/me', [
                'auth_bearer' => $jeton,
                'headers' => [ContexteEtablissement::HEADER => $idEditeur],
            ])->toArray();
            self::assertResponseIsSuccessful();

            self::assertTrue(
                $profil['estEditeur'],
                'Sur l’établissement désigné par EDITOR_TENANT_ID, la réponse doit basculer — sinon le booléen ne mesure rien.',
            );
        } finally {
            // La variable ne doit pas fuir vers les tests suivants : ils vérifient précisément
            // qu'un déploiement sans désignation n'a pas d'éditeur.
            unset($_ENV['EDITOR_TENANT_ID'], $_SERVER['EDITOR_TENANT_ID']);
            putenv('EDITOR_TENANT_ID=');
            self::ensureKernelShutdown();
        }
    }
}
