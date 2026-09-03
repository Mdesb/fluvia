<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\Stock\StockApiTestCase;

/**
 * UNE RÈGLE TENUE SEULEMENT PAR LA BASE SORT EN « 500 INTERNAL SERVER ERROR ».
 *
 * ── COMMENT ÇA A ÉTÉ TROUVÉ ────────────────────────────────────────────────────────────────────
 *
 * En remplissant le stock du compte de test à la demande de Maxime, le 03/09. Quatre cents articles
 * envoyés, **six échecs** — et c'étaient exactement les six auxquels j'avais donné, à dessein, un
 * seuil minimum supérieur au maximum. Le journal du conteneur disait
 * `CONSTRAINT chk_article_seuils failed` ; la réponse HTTP, elle, ne disait rien d'autre que
 * « Internal Server Error ».
 *
 * ⚠ **C'est pour ça qu'on plante des lignes pénibles dans un jeu de test.** Quatre cents lignes
 * propres n'auraient rien montré.
 *
 * ── POURQUOI CE N'EST PAS UN DÉTAIL DE PRÉSENTATION ────────────────────────────────────────────
 *
 * Un 500 annonce « le logiciel est cassé » : il envoie chercher une panne, un journal, une session
 * d'appui. Ici rien n'est cassé — l'exploitant a saisi deux nombres dans le mauvais ordre, et c'est
 * la seule chose qu'on avait besoin de lui dire.
 *
 * Troisième occurrence du même motif dans la nuit du 02 au 03/09, après un doublon de configuration
 * créancier SEPA et une ressource hors du périmètre de l'établissement actif. La base est le dernier
 * rempart, pas le premier.
 */
final class SeuilsIncoherentsTest extends StockApiTestCase
{
    private const EAN_A = '5901234123457';
    private const EAN_B = '40170725';

    /**
     * **Le test qui compte : un refus lisible, pas une erreur interne.**
     *
     * L'assertion sur le code 422 ne suffit pas — c'est le MESSAGE qui distingue une erreur utile
     * d'un refus muet. Sans lui, ce test resterait vert le jour où quelqu'un remplacerait la
     * violation par un `throw` sec.
     */
    public function testDesSeuilsIncoherentsSontRefusesAvecUnMessage(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corps(self::EAN_A, '40.000', '5.000'),
        ]);

        self::assertResponseStatusCodeSame(422);

        $detail = (string) ($client->getResponse()->toArray(false)['detail'] ?? '');
        self::assertStringContainsString('seuil minimum', $detail);
        self::assertStringContainsString('40.000', $detail, 'Le message doit citer les valeurs saisies.');
        self::assertStringContainsString('5.000', $detail);
    }

    /**
     * ⚠ **LE TÉMOIN QUI EMPÊCHE LE PRÉCÉDENT D'ÊTRE VRAI POUR RIEN.**
     *
     * Un contrôle qui refuserait TOUS les articles satisferait aussi l'assertion ci-dessus — et ce
     * test-ci est le seul à le dire. Mêmes données, seuils remis dans l'ordre.
     */
    public function testDesSeuilsCoherentsPassentToujours(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corps(self::EAN_B, '5.000', '50.000'),
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * Le cas limite, parce que la contrainte de base dit `<=` et pas `<` : un minimum ÉGAL au
     * maximum est valide. Un contrôle écrit avec `>=` au lieu de `>` refuserait un réglage
     * parfaitement légitime — « je veux exactement dix en rayon » — et personne ne comprendrait
     * pourquoi.
     */
    public function testUnMinimumEgalAuMaximumEstAccepte(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/article_stocks', $entete + [
            'json' => $this->corps('4006381333931', '10.000', '10.000'),
        ]);

        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function corps(string $ean, string $min, string $max): array
    {
        return [
            'etablissement' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
            'codeEAN' => $ean,
            'libelle' => 'Article de controle des seuils',
            'unite' => 'piece',
            'prixAchatHT' => '4.0000',
            'tauxTvaAchat' => '20.00',
            'seuilMin' => $min,
            'seuilMax' => $max,
        ];
    }
}
