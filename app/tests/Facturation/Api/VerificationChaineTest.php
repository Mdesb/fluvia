<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Tests\Facturation\FacturationApiTestCase;

/**
 * LA VÉRIFICATION DE CHAÎNE DIT LA VÉRITÉ — Y COMPRIS QUAND ELLE N'A RIEN PU VÉRIFIER.
 *
 * ── DEUX DÉFAUTS QUI SE CACHAIENT L'UN L'AUTRE ──────────────────────────────────────────────────
 *
 * 1. La route `GET /api/factures/verifier-chaine` rendait 404 en préproduction et n'avait
 *    **jamais** été atteignable. Personne ne pouvait donc constater le second.
 *
 * 2. Une fois atteignable, elle rendait **trois anomalies sur une chaîne saine** :
 *
 *        trou de séquence : attendu 1, trouvé 0     ← le brouillon, trié en premier
 *        non vérifiable                             ← le brouillon, qui n'a pas d'empreinte
 *        chaînage rompu : empreinte précédente      ← la vraie facture, décalée par lui
 *
 *    La requête ratissait tous les documents du profil, brouillons compris. Or un brouillon n'est
 *    pas dans la chaîne : il porte `numeroSequence = 0` et aucune empreinte.
 *
 * ⚠ Cette vérification aurait annoncé « chaîne rompue » à un contrôleur sur une installation
 * intacte. C'est le pire résultat possible pour un contrôle de conformité — pire qu'une absence,
 * parce qu'il déclenche une enquête sur un problème qui n'existe pas.
 *
 * ── ET LE ZÉRO QUI RASSURAIT ────────────────────────────────────────────────────────────────────
 *
 * Sans périmètre résoluble, le point rendait `['intacte' => true, 'nbDocuments' => 0]`. Une absence
 * de périmètre n'est pas une chaîne intacte : c'est une question à laquelle on n'a pas répondu.
 */
final class VerificationChaineTest extends FacturationApiTestCase
{
    /**
     * @return array{0: object, 1: array<string, mixed>}
     */
    private function unScelleEtUnBrouillon(): array
    {
        [$client, $entete] = $this->adminSurA();

        $corps = [
            'destinataire' => [
                'type' => 'personne_morale',
                'raisonSociale' => 'Client de contrôle',
                'siret' => '12345678900011',
                'adresse' => ['rue' => '3 rue du Test', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
            ],
            'lignes' => [[
                'designation' => 'Prestation',
                'quantite' => 1,
                'prixUnitaireHT' => '100.00',
                'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva('Taux normal 20 %'),
            ]],
        ];

        // Une facture ÉMISE — donc scellée, donc dans la chaîne.
        $premiere = $client->request('POST', '/api/factures', $entete + ['json' => $corps])->toArray();
        $client->request('POST', '/api/factures/' . $premiere['id'] . '/emettre', $entete);

        // Et une seconde laissée en BROUILLON — hors chaîne, et c'est elle qui cassait tout.
        $client->request('POST', '/api/factures', $entete + ['json' => $corps]);

        return [$client, $entete];
    }

    /** Un brouillon ne casse plus une chaîne saine. */
    public function testUnBrouillonNeRompPlusUneChaineSaine(): void
    {
        [$client, $entete] = $this->unScelleEtUnBrouillon();

        $rapport = $client->request('GET', '/api/factures/verifier-chaine', $entete)->toArray();

        // Témoin positif : sans lui, un rapport « intacte » sur ZÉRO document passerait ce test —
        // et c'est exactement le mensonge qu'on vient de retirer d'ici.
        self::assertGreaterThan(0, $rapport['nbDocuments'], 'La vérification doit porter sur au moins un document scellé.');

        self::assertTrue(
            $rapport['intacte'],
            'Une chaîne saine doit être déclarée intacte, brouillon présent. Anomalies : '
            . json_encode($rapport['anomalies'], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * ⚠ Sans périmètre, on REFUSE au lieu de rassurer.
     *
     * Le compte utilisé n'est rattaché à aucun profil exploitant : c'est le cas où le point rendait
     * « intacte : true » sur zéro document.
     */
    public function testSansPerimetreLaVerificationRefuseAuLieuDeRassurer(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/factures/verifier-chaine?profilExploitant=' . self::PROFIL_INEXISTANT, $entete);

        // 404 depuis le 08/10, comme un profil d'un autre perimetre (`ChainCheckScopeTest`) : les deux
        // cas ne doivent pas se distinguer. Ce qui compte ici ne change pas : on refuse, on ne rassure pas.
        self::assertResponseStatusCodeSame(404, "Une absence de périmètre n'est pas une chaîne intacte.");

        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('PAS ete verifiee', $corps, 'Le message doit dire que rien n\'a été vérifié.');
    }

    /** Un profil inexistant mais syntaxiquement valide. */
    private const PROFIL_INEXISTANT = '00000000-0000-4000-8000-000000000000';
}
