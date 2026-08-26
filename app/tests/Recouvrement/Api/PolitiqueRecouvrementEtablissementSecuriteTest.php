<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Recouvrement\RecouvrementApiTestCase;

/**
 * D41 — `PolitiqueRecouvrement.etablissement` ne doit jamais pouvoir être posé/déplacé depuis le corps
 * d'une requête client : il est résolu côté serveur (contexte établissement actif, en-tête
 * `X-Etablissement`), sinon un appelant pourrait rattacher/déplacer une politique vers un autre
 * établissement que le sien (D3/D8).
 *
 * ⚠ Sans le correctif (`etablissement` dans `politique_recouvrement:write`, pas de processeur dédié),
 * ces deux tests sont ROUGES :
 * - le POST dénormalise l'établissement A envoyé dans le corps (au lieu de B, le contexte) ; comme A a
 *   déjà une politique (fixture Sport), l'INSERT viole la contrainte unique `etablissement` et la
 *   requête échoue (`assertResponseIsSuccessful` rouge) — la vulnérabilité se manifeste ici par une
 *   erreur plutôt qu'un succès silencieux, mais le test échoue bien sans le correctif ;
 * - le PATCH, lui, réussit à déplacer effectivement la politique de démonstration de A vers B : les
 *   assertions sur `etablissement` échouent alors proprement.
 */
final class PolitiqueRecouvrementEtablissementSecuriteTest extends RecouvrementApiTestCase
{
    public function testCreerNePeutPasImposerLEtablissementDepuisLeCorps(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        // Contexte serveur = B (en-tête), mais tentative de rattachement à A via le corps.
        // B n'a pas encore de politique (seule A en a une via SportFixtures) : la création est possible.
        $enteteContexteB = [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idB],
        ];

        $cree = $client->request('POST', '/api/politique_recouvrements', $enteteContexteB + [
            'json' => [
                'nbRepresentationsMax' => 3,
                'etablissement' => '/api/etablissements/' . $idA,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $idB,
            $cree['etablissement'],
            'L\'établissement doit être celui du contexte serveur (B), jamais celui envoyé dans le corps (A).',
        );
        self::assertStringNotContainsString($idA, $cree['etablissement']);
    }

    public function testModifierNePeutPasDeplacerLaPolitiqueVersUnAutreEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $politique = $this->politiqueDemo();
        self::assertSame($idA, (string) $politique->getEtablissement()?->getId());

        $client->request('PATCH', '/api/politique_recouvrements/' . $politique->getId(), [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'nbRepresentationsMax' => 4,
                'etablissement' => '/api/etablissements/' . $idB,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame(4, $donnees['nbRepresentationsMax'], 'Les autres champs restent modifiables.');
        self::assertStringContainsString(
            $idA,
            $donnees['etablissement'],
            'L\'établissement reste celui d\'origine (A) : ne peut pas être déplacé via le corps.',
        );
        self::assertStringNotContainsString($idB, $donnees['etablissement']);
    }
}
