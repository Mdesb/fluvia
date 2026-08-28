<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

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
}
