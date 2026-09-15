<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Produit;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;
use App\Vente\Entity\Vente;

/**
 * Confirmation d'une commande en ligne quand ses places ont été prises entre le paiement et le retour
 * de paiement (RG-M3-08, §0 décision n°8).
 *
 * `ConfirmerCommandeHandler` créait les réservations APRÈS la validation, sans aucun contrôle de jauge
 * (le seul contrôle avait lieu à l'initiation du paiement), et chacune valait une place quelle que
 * soit la quantité de la ligne. Le premier cas échoue donc sur le code d'origine par une commande
 * confirmée sur un créneau plein ; le second par une réservation d'une place pour deux billets.
 *
 * La concurrence est jouée par `ConcurrentSlotWriter` : la réservation de remplissage est posée avant
 * le paiement, gonflée pendant le retour de paiement.
 */
final class ConcurrentOrderConfirmationTest extends BoutiqueApiTestCase
{
    use ConcurrentSlotWriter;

    public function testAPaidOrderWhosePlacesWereTakenIsNotConfirmedAndAsksForARefund(): void
    {
        $commande = $this->payerDeuxBillets();

        // Il ne restera qu'une place pour deux billets.
        $concurrent = $this->holdSlotLock($commande['creneau'], $commande['remplissage'], $this->fillerQuantityLeaving($commande['creneau'], 1));
        $debut = microtime(true);
        $reponse = $this->retourPaiementAccepte($commande);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseIsSuccessful();
        self::assertSame('conflit_inventaire', $reponse['statut'] ?? null, 'Une commande dont les places ont été prises ne se confirme pas : ' . json_encode($reponse));
        $this->assertWaitedForTheConcurrentWrite($attente);

        $this->em()->clear();
        $vente = $this->em()->getRepository(Vente::class)->find($reponse['vente']);
        self::assertInstanceOf(Vente::class, $vente);
        self::assertInstanceOf(DemandeRemboursement::class, $this->em()->getRepository(DemandeRemboursement::class)->findOneBy(['vente' => $vente]), 'Décision n°8 : paiement capturé, place indisponible → demande de remboursement.');
        $occupantes = array_filter(
            $this->em()->getRepository(Reservation::class)->findBy(['venteRattachee' => $vente]),
            static fn (Reservation $r) => $r->getStatut()->occupePlace() || $r->getStatut() === StatutReservation::AConfirmer,
        );
        self::assertCount(0, $occupantes, 'Aucune place ne doit être tenue pour une commande non confirmée.');
    }

    public function testAPaidOrderTakesThePlacesStillFreeWithTheLineQuantity(): void
    {
        $commande = $this->payerDeuxBillets();

        // Il restera exactement les deux places de la ligne.
        $concurrent = $this->holdSlotLock($commande['creneau'], $commande['remplissage'], $this->fillerQuantityLeaving($commande['creneau'], 2));
        $debut = microtime(true);
        $reponse = $this->retourPaiementAccepte($commande);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseIsSuccessful();
        self::assertSame('confirme', $reponse['statut'] ?? null, 'Les places réellement libres doivent être prises : ' . json_encode($reponse));
        $this->assertWaitedForTheConcurrentWrite($attente);

        $this->em()->clear();
        $vente = $this->em()->getRepository(Vente::class)->find($reponse['vente']);
        $reservations = $this->em()->getRepository(Reservation::class)->findBy(['venteRattachee' => $vente]);
        self::assertCount(1, $reservations, 'Une réservation par ligne de commande.');
        self::assertSame(2, $reservations[0]->getQuantity(), 'Deux billets pèsent deux places sur la jauge.');
        self::assertSame(StatutReservation::Confirmee, $reservations[0]->getStatut());
    }

    /**
     * Panier invité, une ligne de deux billets timed-entry, jusqu'à l'initiation du paiement.
     *
     * @return array{client: object, entete: array<string, string>, panier: string, paiement: array<string, mixed>, creneau: string, remplissage: string}
     */
    private function payerDeuxBillets(): array
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $creneaux = $client->request('GET', '/api/boutique/produits/' . $produit->getId() . '/creneaux')->toArray();
        self::assertNotEmpty($creneaux['creneaux']);
        $idCreneau = (string) $creneaux['creneaux'][0]['creneau'];

        // Posée AVANT le paiement : la vérification de disponibilité à l'initiation doit passer.
        $payeur = $this->em()->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $payeur);
        $beneficiaire = $this->em()->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertInstanceOf(Beneficiaire::class, $beneficiaire);
        $remplissage = $this->persistFillerReservation($idCreneau, $beneficiaire);

        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'creneau' => $idCreneau, 'quantite' => 2],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $ligneId = (string) $panier['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => 'invite.concurrence@example.test'],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', [
            'headers' => $entete,
            'json' => ['rgpd' => true],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Martin', 'prenom' => 'Lea', 'dateNaissance' => '1990-01-01']]]],
        ]);
        self::assertResponseIsSuccessful();

        $paiement = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($paiement['referenceTransaction']);

        return ['client' => $client, 'entete' => $entete, 'panier' => $panierId, 'paiement' => $paiement, 'creneau' => $idCreneau, 'remplissage' => $remplissage];
    }

    /**
     * @param array{client: object, entete: array<string, string>, panier: string, paiement: array<string, mixed>} $commande
     *
     * @return array<string, mixed>
     */
    private function retourPaiementAccepte(array $commande): array
    {
        return $commande['client']->request('POST', '/api/boutique/paniers/' . $commande['panier'] . '/retour-paiement', [
            'headers' => $commande['entete'],
            'json' => [
                'referenceTransaction' => $commande['paiement']['referenceTransaction'],
                'recu' => $commande['paiement']['simulation']['accepte'],
            ],
        ])->toArray(false);
    }
}
