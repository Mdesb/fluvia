<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * LE TICKET, CHAMP PAR CHAMP — le filet posé AVANT d'extraire sa construction.
 *
 * ── POURQUOI CE TEST EXISTE ────────────────────────────────────────────────────────────────────
 *
 * `TicketProcessor` fabrique le document dans sa propre méthode. Le rendu PDF du lot 9 (D124) aura
 * besoin du même document, et le construire une seconde fois créerait deux vérités sur le même
 * papier — le défaut que `TicketProcessor::process()` raconte déjà pour la règle d'impression, et
 * qui se paie au PREMIER correctif.
 *
 * Extraire est donc juste. Mais un remplacement de source ne se prouve pas en vérifiant que les
 * champs sont toujours là : il se prouve par l'égalité de la SORTIE. Un extracteur qui oublie
 * `remiseType` rend un tableau qui a l'air complet, et le papier perd une mention opposable sans
 * qu'un seul test tombe.
 *
 * Ce test épingle donc la réponse ENTIÈRE — l'ensemble exact des clés, au niveau racine comme dans
 * les lignes, et les valeurs de tout ce qui n'est pas volatil. Il est écrit et joué vert AVANT
 * l'extraction ; il doit rester vert APRÈS, sans être retouché. S'il faut le modifier pour le faire
 * repasser, c'est que la sortie a changé — donc que le papier a changé.
 */
final class DocumentTicketTest extends VenteApiTestCase
{
    /** L'ensemble des clés de la réponse, exactement — ni plus, ni moins. */
    public function testLesClesDuTicketSontFigees(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ticket = $this->ticketDUneVente($client, $entete);

        self::assertSame([
            'vente',
            'numero',
            'date',
            'lignes',
            'total',
            'totalRemises',
            'mode',
            'imprime',
            'impressionAutomatique',
            'venteGratuite',
            'duplicata',
            'renvoiPropose',
            'renvoye',
            'canal',
        ], array_keys($ticket), 'Une cle qui disparait est une mention qui disparait du papier.');
    }

    /** L'ensemble des clés d'une ligne, exactement — c'est là que se perdent les mentions. */
    public function testLesClesDuneLigneSontFigees(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ticket = $this->ticketDUneVente($client, $entete);

        self::assertCount(1, $ticket['lignes']);
        self::assertSame([
            'id',
            'libelle',
            'tarif',
            'quantite',
            'prixUnitaire',
            'impactOptionsUnitaire',
            'remiseLigne',
            'remiseType',
            'montantLigne',
            'optionsSelectionnees',
            'promotionsAppliquees',
        ], array_keys($ticket['lignes'][0]));
    }

    /** Les valeurs non volatiles, epinglees — l'egalite de la sortie, pas la presence des champs. */
    public function testLesValeursDuTicketSontFigees(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ticket = $this->ticketDUneVente($client, $entete);

        $volatiles = ['vente', 'numero', 'date'];
        $stable = array_diff_key($ticket, array_flip($volatiles));
        $stable['lignes'][0] = array_diff_key($stable['lignes'][0], array_flip(['id']));

        self::assertEquals([
            'lignes' => [[
                // ⚠ UN TABLEAU TRADUISIBLE, PAS UNE CHAÎNE — et c'est le libellé qui va sur le papier.
                // Un rendu qui l'écrit tel quel imprime « Array ». Le rendu papier devra choisir une
                // langue, donc décider laquelle : celle de l'établissement, pas celle du caissier.
                'libelle' => ['fr' => 'Carte 10=12 piscine'],
                'tarif' => 'Plein tarif',
                'quantite' => 1,
                'prixUnitaire' => '45.00',
                'impactOptionsUnitaire' => '0.00',
                // `null`, pas `'0.00'` : `LigneVente::$remiseLigne` est nullable et vaut null sans
                // remise. « Aucune remise » et « une remise de zéro » ne sont pas le même fait.
                'remiseLigne' => null,
                'remiseType' => null,
                'montantLigne' => '45.00',
                'optionsSelectionnees' => [],
                'promotionsAppliquees' => [],
            ]],
            'total' => '45.00',
            'totalRemises' => '0.00',
            'mode' => 'imprimer',
            'imprime' => true,
            'impressionAutomatique' => true,
            'venteGratuite' => false,
            // ⚠ VRAI, ET CONTRE-INTUITIF — voir `testLePremierAppelExpliciteEstDejaUnDuplicata`.
            'duplicata' => true,
            'renvoiPropose' => false,
            'renvoye' => false,
            'canal' => null,
        ], $stable);
    }

    /**
     * ⚠ LE PREMIER APPEL EXPLICITE REND DÉJÀ « DUPLICATA » : C'EST LE COMPORTEMENT DE `main`,
     * ÉPINGLÉ TEL QUEL POUR L'EXTRACTION, PAS LA RÈGLE À VENIR.
     *
     * Aujourd'hui, au-dessus du seuil et en session, `ValiderVenteService` marque la vente imprimée à
     * la validation (`TicketPrintingPolicy::marqueImprimeeALaValidation`), et la mention se lit de
     * `imprime`. Or aucune imprimante n'est pilotée : D124 dit qu'une édition ne compte que si elle
     * produit le papier et que l'affichage à l'écran n'en est pas une (Q-C3), et la mention viendra du
     * journal des éditions (Q-C1, G-14). Ce test sera donc changé délibérément au lot 9 (É39).
     *
     * Ce qu'il NE VOIT PAS : la valeur est lue de l'entité AVANT qu'elle soit marquée imprimée, et un
     * extracteur qui la relirait après coup rendrait « DUPLICATA » sur l'original. Ici la vente est
     * déjà marquée à la validation, donc les deux appels rendent vrai quoi qu'il arrive : c'est
     * `TicketSingleDocumentTest::testUnderTheThresholdTheFirstTicketIsTheOriginal` qui le tient.
     */
    public function testLePremierAppelExpliciteEstDejaUnDuplicata(): void
    {
        [$client, $entete] = $this->adminSurA();
        $venteId = $this->venteValidee($client, $entete);

        $premier = $client->request('POST', '/api/ventes/' . $venteId . '/ticket', $entete + [
            'json' => ['mode' => 'imprimer'],
        ])->toArray();
        self::assertTrue($premier['duplicata'], 'Comportement de main : la validation a pose `imprime` (D124 le change au lot 9).');

        $second = $client->request('POST', '/api/ventes/' . $venteId . '/ticket', $entete + [
            'json' => ['mode' => 'imprimer'],
        ])->toArray();
        self::assertTrue($second['duplicata'], 'Sans cette mention, un second papier se fait passer pour l\'original.');
    }

    /** @param array<string, mixed> $entete @return array<string, mixed> */
    private function ticketDUneVente(object $client, array $entete): array
    {
        $venteId = $this->venteValidee($client, $entete);

        return $client->request('POST', '/api/ventes/' . $venteId . '/ticket', $entete + [
            'json' => ['mode' => 'imprimer'],
        ])->toArray();
    }

    /** @param array<string, mixed> $entete */
    private function venteValidee(object $client, array $entete): string
    {
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '45.00'],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);

        return $vente['id'];
    }
}
