<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Acces\Entity\DroitAcces;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Reservation\Entity\Creneau;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\BilletSupport;

/**
 * Un billet acheté en ligne sur un créneau vaut pour son créneau, pas pour le jour de l'achat.
 *
 * L'entrée unitaire vaut désormais le jour de la vente (décision de Maxime du 08/10). Sans ce test,
 * une visite achetée aujourd'hui pour lundi prochain serait refusée lundi. Le billet de la boutique
 * portait déjà ces horaires (`BilletQrMeta`), le droit d'accès non : il n'avait aucune fenêtre.
 */
final class TimedTicketAccessWindowTest extends BoutiqueApiTestCase
{
    public function testEveryTicketOfATimedLineOpensDuringItsSlot(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = ['headers' => [PanierProprietaireGuard::HEADER => $jeton]];
        $url = '/api/boutique/paniers/' . $panierId;

        $creneauId = $client->request('GET', '/api/boutique/produits/' . $produit->getId() . '/creneaux')->toArray()['creneaux'][0]['creneau'];
        $ligneId = (string) $client->request('POST', $url . '/lignes', $entete + ['json' => [
            'produit' => (string) $produit->getId(), 'creneau' => $creneauId, 'quantite' => 2,
        ]])->toArray()['lignes'][0]['id'];
        $client->request('POST', $url . '/identifier', $entete + ['json' => ['mode' => 'invite', 'email' => 'visite@example.test']]);
        $client->request('POST', $url . '/consentement', $entete + ['json' => ['mentionVersion' => 'mention-test']]);
        $client->request('POST', $url . '/beneficiaires', $entete + ['json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Martin', 'prenom' => 'Camille']]]]]);
        $paiement = $client->request('POST', $url . '/payer', $entete)->toArray();
        $venteId = (string) $client->request('POST', $url . '/retour-paiement', $entete + ['json' => [
            'referenceTransaction' => $paiement['referenceTransaction'],
            'recu' => $paiement['simulation']['accepte'],
        ]])->toArray()['vente'];

        $em = $this->em();
        $em->clear();
        $creneau = $em->getRepository(Creneau::class)->find($creneauId);
        $supports = $em->getRepository(BilletSupport::class)->findBy(['vente' => $venteId]);
        self::assertCount(2, $supports);
        foreach ($supports as $support) {
            $droit = $em->getRepository(DroitAcces::class)->findOneBy(['billetSupportRef' => $support->getId()]);
            self::assertInstanceOf(DroitAcces::class, $droit);
            self::assertEquals($creneau?->getDebut(), $droit->getFenetreDebut(), 'Le billet daté ouvre au début de son créneau.');
            self::assertEquals($creneau?->getFin(), $droit->getFenetreFin(), 'Et ferme à sa fin.');
        }
    }
}
