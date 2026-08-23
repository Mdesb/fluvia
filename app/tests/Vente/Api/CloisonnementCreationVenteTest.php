<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Non-régression des n°10 et n°15 (D8, corrigés le 23/08) : deux défauts distincts dans le même
 * Processor, et le second n'a été vu que parce que le premier était corrigé.
 *
 * **n°10 — la session de caisse.** Elle arrivait du corps, résolue par un `find()` direct sans aucun
 * contrôle. Ce n'était pas qu'une fuite : plus bas, `->setEtablissement($session->getEtablissement())`
 * fait que **la session détermine l'établissement de la vente créée**. Passer la session d'autrui n'y
 * donnait pas accès, elle y **créait une écriture comptable**.
 *
 * **n°15 — la clé d'idempotence.** Trouvée par le garde-fou de cloisonnement *pendant* la correction
 * du n°10 : en ajoutant un contrôle de périmètre au fichier, la règle « le contrôle porte sur l'entité
 * résolue » a demandé si l'autre variable était couverte. Elle ne l'était pas. La colonne n'est pas
 * unique en base, et la vente trouvée était renvoyée telle quelle : connaître la clé d'une vente d'un
 * autre établissement en rendait le montant, les lignes et le client.
 *
 * **Le montage ne mute rien.** La vente et la session restent sur A ; c'est **l'établissement actif**
 * qui passe à B. L'administrateur socle est affecté aux deux, donc l'en-tête B lui est légitime — ce
 * qui ne l'est pas, c'est d'atteindre depuis B ce qui vit sur A.
 */
final class CloisonnementCreationVenteTest extends VenteApiTestCase
{
    public function testUneSessionDunAutreEtablissementNeCreeAucuneVente(): void
    {
        [$client, $enteteA] = $this->adminSurA();
        $sessionDeA = $this->ouvrirSession($client, $enteteA)['id'];

        $reponse = $client->request('POST', '/api/ventes', $this->enteteSurB($enteteA) + [
            'json' => ['session' => '/api/session_caisses/' . $sessionDeA],
        ]);

        self::assertSame(
            404,
            $reponse->getStatusCode(),
            'Une session hors périmètre doit être introuvable, jamais interdite : '
            . (string) $reponse->getContent(false),
        );
    }

    public function testUneCleDidempotenceNeRendJamaisLaVenteDunAutreEtablissement(): void
    {
        [$client, $enteteA] = $this->adminSurA();
        $sessionDeA = $this->ouvrirSession($client, $enteteA)['id'];

        $cle = (string) Uuid::v4();

        $venteDeA = $client->request('POST', '/api/ventes', $enteteA + [
            'json' => [
                'session' => '/api/session_caisses/' . $sessionDeA,
                'cleIdempotence' => $cle,
            ],
        ])->toArray();
        self::assertNotEmpty($venteDeA['id']);

        // Contrôle positif : la même clé, sur le même établissement, rend bien la même vente. Sans lui,
        // une idempotence cassée rendrait le test vert pour la mauvaise raison.
        $rejoue = $client->request('POST', '/api/ventes', $enteteA + [
            'json' => [
                'session' => '/api/session_caisses/' . $sessionDeA,
                'cleIdempotence' => $cle,
            ],
        ])->toArray();
        self::assertSame($venteDeA['id'], $rejoue['id'], 'L\'idempotence doit continuer de fonctionner sur son propre établissement.');

        // La même clé, vue depuis B : la vente de A ne doit jamais être rendue.
        $reponse = $client->request('POST', '/api/ventes', $this->enteteSurB($enteteA) + [
            'json' => [
                'session' => '/api/session_caisses/' . $sessionDeA,
                'cleIdempotence' => $cle,
            ],
        ]);

        self::assertStringNotContainsString(
            (string) $venteDeA['id'],
            (string) $reponse->getContent(false),
            'La vente d\'un autre établissement ne doit jamais être rendue par sa clé d\'idempotence.',
        );
    }

    /**
     * @param array<string, mixed> $enteteA
     *
     * @return array<string, mixed>
     */
    private function enteteSurB(array $enteteA): array
    {
        return [
            'auth_bearer' => $enteteA['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ];
    }
}
