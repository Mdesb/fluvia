<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Tests\Facturation\FacturationApiTestCase;

/**
 * RG-FACT-08 : ON N'ÉMET PAS UNE FACTURE SANS SAVOIR À QUI.
 *
 * ── LA RÈGLE EXISTAIT, JUSTE, ET SANS AUCUN APPELANT ────────────────────────────────────────────
 *
 * `DestinataireFacturation::anomalies()` l'implémente depuis l'origine. Relevé avec témoin par
 * `allaccess-b8` : une définition, zéro appel. Trois destinataires en base, trois sans adresse.
 *
 * ── POURQUOI LA RÈGLE NE PORTE PAS SUR LE MONTANT ───────────────────────────────────────────────
 *
 * Le droit admet une facture SIMPLIFIÉE en B2C sous un certain seuil. On aurait pu y adosser la
 * garde — on ne l'a pas fait, délibérément : ce serait faire dépendre une mention légale d'un nombre
 * qu'on ne peut pas vérifier depuis le code et qui bouge avec les textes.
 *
 * ⚠ La règle porte donc sur **qui est le destinataire**, jamais sur combien il doit :
 *
 *     personne morale · organisme public   raison sociale + SIRET + adresse, toujours
 *     particulier nommé                    adresse exigée — nommer quelqu'un, c'est pouvoir l'atteindre
 *     aucun destinataire                   ce n'est pas une facture, c'est un ticket
 *
 * Et c'est le troisième cas qui débloque tout : facturer une vente **sans dire à qui** n'est pas une
 * facture simplifiée. Le point d'entrée accepte déjà un destinataire — c'est le geste du guichet.
 */
final class GardeDestinataireTest extends FacturationApiTestCase
{
    /** Une personne morale sans SIRET ni adresse : refusée, et le message nomme ce qui manque. */
    public function testUnePersonneMoraleIncompleteEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => [
                'destinataire' => ['type' => 'personne_morale', 'raisonSociale' => 'Club sans papiers'],
                'lignes' => [[
                    'designation' => 'Prestation',
                    'quantite' => 1,
                    'prixUnitaireHT' => '100.00',
                    'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva('Taux normal 20 %'),
                ]],
            ],
        ])->toArray();

        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);

        self::assertResponseStatusCodeSame(422);

        // ⚠ LE MESSAGE EST LA MOITIÉ QUI COMPTE : il doit nommer CHAQUE manque d'un coup. Les rendre
        // un par un obligerait l'exploitant à réessayer autant de fois qu'il manque de champs — et
        // c'est le genre de friction qui fait chercher un contournement plutôt qu'une correction.
        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('SIRET', $corps, 'Le message doit nommer le SIRET manquant.');
        self::assertStringContainsString('adresse', $corps, "Le message doit nommer l'adresse manquante.");
    }

    /**
     * ⚠ LE CAS QUI DISTINGUE UNE GARDE D'UN BLOCAGE : un destinataire complet passe.
     *
     * Sans lui, une garde qui refuserait TOUTE émission passerait le premier test avec le même vert.
     */
    public function testUnDestinataireCompletPasse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => [
                'destinataire' => [
                    'type' => 'personne_morale',
                    'raisonSociale' => 'Club en règle',
                    'siret' => '12345678900011',
                    'adresse' => ['rue' => '1 rue de Test', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
                ],
                'lignes' => [[
                    'designation' => 'Prestation',
                    'quantite' => 1,
                    'prixUnitaireHT' => '100.00',
                    'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva('Taux normal 20 %'),
                ]],
            ],
        ])->toArray();

        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);

        self::assertSame(201, $emise->getStatusCode(), 'Une facture conforme doit rester émissible.');
        self::assertNotEmpty($emise->toArray()['numero'], 'Témoin : elle porte bien un numéro.');
    }
}
