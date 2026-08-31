<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une vente sans aucune ligne ne se valide pas — le scellement NF525 est irréversible.
 *
 * ── LE DÉFAUT, TROUVÉ EN VENDANT POUR DE VRAI ──────────────────────────────────────────────────
 *
 * Le 30/08, en vendant un billet en préproduction pour prouver le pont vente → contrôle d'accès :
 * l'ajout de ligne a échoué sur une option obligatoire, la vente est restée vide, et `POST /valider`
 * a rendu **201**. Mesuré ensuite dans la chaîne fiscale :
 *
 *     numero_sequence   type_operation   numero             lignes   paiements
 *     1                 vente            D-F3FAB70F-00001        0           0
 *     2                 vente            D-F3FAB70F-00002        1           1
 *
 * ⚠ La vente vide a consommé le **numéro 1** de la chaîne NF525 et posé l'empreinte que la suivante
 * chaîne. Cette chaîne est inaltérable par construction : le numéro ne se libère pas, l'entrée ne se
 * retire pas. À une vraie caisse, un clic de trop laisse une écriture fiscale définitive, et la
 * clôture du jour la compte.
 *
 * ── POURQUOI AUCUNE GARDE NE L'ATTRAPAIT ───────────────────────────────────────────────────────
 *
 * Trois gardes existaient — statut, reste dû, point de vente. La seule qui regarde l'argent exige un
 * reste dû nul… et **une vente sans ligne a un reste dû de zéro**. Elle passait donc en satisfaisant
 * parfaitement le contrôle. C'est le piège de l'assertion vacueusement vraie, transposé à une règle
 * métier : un contrôle qui réussit d'autant mieux qu'il n'y a rien à contrôler.
 *
 * ── ET CE TEST NE DIT PAS « TOTAL NUL » ────────────────────────────────────────────────────────
 *
 * Un total nul est légitime — billet offert, remise de 100 %, geste commercial. Ces ventes portent
 * des lignes et ont leur place au journal. Le second cas de ce fichier est le témoin qui distingue
 * « on refuse une vente vide » de « on refuse une vente à zéro euro » : sans lui, une garde écrite
 * sur le montant passerait le premier test et interdirait un cas réel.
 */
final class VenteSansLigneTest extends VenteApiTestCase
{
    public function testUneVenteSansAucuneLigneEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        // Aucune ligne, aucun paiement : le reste dû vaut zéro, donc la garde du reste dû passe.
        $client->request('POST', '/api/ventes/'.$vente['id'].'/valider', $entete + ['json' => []]);

        self::assertResponseStatusCodeSame(422);

        // ⚠ Le message doit nommer la CONSÉQUENCE, pas seulement la règle. Un agent qui lit
        // « validation impossible » cherche ce qu'il a mal fait ; « le scellement consommerait un
        // numéro pour rien » lui dit pourquoi personne ne pourra le rattraper ensuite.
        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('sans aucune ligne', $corps);
        self::assertStringContainsString('NF525', $corps);

        // Et rien n'a été scellé.
        $em = $this->em();
        $em->clear();
        /** @var Vente $apres */
        $apres = $em->getRepository(Vente::class)->find($vente['id']);
        self::assertSame('en_cours', $apres->getStatut()->value, 'La vente doit rester en cours.');
    }

    /**
     * ⚠ LE TÉMOIN : une vente à zéro euro, mais avec une ligne, se valide.
     *
     * Sans lui, une garde écrite sur le MONTANT au lieu du nombre de lignes passerait le premier
     * test — et interdirait le billet offert, la remise de 100 % et le geste commercial, c'est-à-dire
     * trois cas réels que personne n'aurait vus disparaître avant qu'un exploitant ne les réclame.
     */
    public function testUneVenteAZeroEuroAvecUneLigneSeValide(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $client->request('POST', '/api/ventes/'.$vente['id'].'/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/'.$this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/'.$this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
                // ⚠ `remiseLigne` et `remiseType` sont en `vente:read` SEULEMENT — mais
                // `AjoutLigneHandler:89` les lit dans le CORPS BRUT, hors des groupes de
                // sérialisation. Une première version de ce test a conclu du groupe que le champ
                // n'était pas écrivable et l'a posé par l'ORM : la remise n'a alors rien changé,
                // parce que `recalculerVente()` somme les `montantLigne` déjà stockés et n'appelle
                // jamais `recalculerLigne()`. Les groupes ne disent pas les écritures.
                'remiseLigne' => 100,
                'remiseType' => 'pourcentage',
            ],
        ]);
        self::assertResponseIsSuccessful('ajout de la ligne remisée à 100 %');

        $client->request('POST', '/api/ventes/'.$vente['id'].'/valider', $entete + ['json' => []]);

        self::assertResponseIsSuccessful(
            'Une vente à zéro euro AVEC une ligne doit passer : billet offert, remise totale, geste commercial.'
        );
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
