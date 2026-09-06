<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Billet\GenerateurPdfBillet;
use App\Boutique\Billet\GenerateurPdfFacture;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Enum\TypeDestinataire;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Boutique\Support\MailCollector;
use App\Vente\Entity\Vente;

/**
 * E-billet concret : QR image, billet PDF, e-mail HTML de confirmation avec pièce jointe
 * (US-L8-08, RG-M3-04/08/14, CA-11/CA-12) — au-delà du seul flag `repliQr` déjà couvert par
 * `TunnelAchatSimpleTest`, ce test vérifie que le **contenu réel** (HTML + PDF) est produit.
 */
final class EBilletEtEmailTest extends BoutiqueApiTestCase
{
    public function testCa11EmailDeConfirmationHtmlAvecBilletPdfEnPieceJointe(): void
    {
        $resultat = $this->finaliserAchatSimple('email.confirmation.pdf@example.test');

        /** @var MailCollector $collecteur */
        $collecteur = static::getContainer()->get(MailCollector::class);
        $message = $collecteur->dernier();
        self::assertNotNull($message, 'Un e-mail de confirmation doit avoir été émis (CA-11).');

        $destinataires = array_map(static fn ($adresse) => $adresse->getAddress(), $message->getTo());
        self::assertSame([$resultat['email']], $destinataires);
        self::assertStringContainsString($resultat['vente']->getNumero(), (string) $message->getSubject());

        $html = $message->getHtmlBody();
        self::assertIsString($html, 'Le corps HTML doit être renseigné (gabarit Twig).');
        self::assertStringContainsString($resultat['vente']->getNumero(), $html);
        self::assertStringContainsString('billet', mb_strtolower($html));

        $piecesJointes = $message->getAttachments();
        self::assertNotEmpty($piecesJointes, 'Le billet PDF doit être joint (§4.8 spec-boutique.md).');
        $pieceJointe = $piecesJointes[0];
        self::assertStringStartsWith('%PDF', $pieceJointe->getBody(), 'La pièce jointe est un vrai PDF (dompdf).');
        self::assertSame('application', $pieceJointe->getMediaType());
        self::assertSame('pdf', $pieceJointe->getMediaSubtype());
    }

    public function testGenerateurPdfBilletProduitUnPdfNonVideAvecQrEmbarque(): void
    {
        $resultat = $this->finaliserAchatSimple('billet.pdf.direct@example.test');

        /** @var GenerateurPdfBillet $generateur */
        $generateur = static::getContainer()->get(GenerateurPdfBillet::class);
        $pdf = $generateur->genererPourVente($resultat['vente']);

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(2000, \strlen($pdf), 'Le PDF embarque au moins un billet avec son QR (SVG en data URI).');
    }

    public function testFactureNonDisponibleParDefautSansForcerLaGeneration(): void
    {
        $resultat = $this->finaliserAchatSimple('sans.facture@example.test');

        /** @var GenerateurPdfFacture $generateur */
        $generateur = static::getContainer()->get(GenerateurPdfFacture::class);
        self::assertNull(
            $generateur->genererSiDisponible($resultat['vente']),
            'Aucun mécanisme du dépôt n\'émet encore automatiquement de facture pour une vente en ligne '
            . '(module Facturation, `POST /factures/depuis-vente` seulement, non déclenché ici) : à noter, non forcé.',
        );
    }

    public function testFacturePdfJointeQuandUneFactureExisteDejaPourLaVente(): void
    {
        $resultat = $this->finaliserAchatSimple('avec.facture@example.test');
        $this->creerFactureAcquitteePour($resultat['vente']);

        /** @var GenerateurPdfFacture $generateur */
        $generateur = static::getContainer()->get(GenerateurPdfFacture::class);
        $pdf = $generateur->genererSiDisponible($resultat['vente']);

        self::assertNotNull($pdf, 'Si une Facture émise existe déjà pour la Vente, elle est jointe (best-effort).');
        self::assertStringStartsWith('%PDF', $pdf);
    }

    /** @return array{vente: Vente, email: string} */
    private function finaliserAchatSimple(string $email): array
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        self::assertResponseIsSuccessful();
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => $email],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', [
            'headers' => $entete,
            'json' => ['rgpd' => true],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Martin', 'prenom' => 'Alice', 'dateNaissance' => '1985-05-05']]]],
        ]);
        self::assertResponseIsSuccessful();

        $reponsePayer = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseIsSuccessful();
        $donneesPaiement = $reponsePayer->toArray();

        $reponseConfirmation = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => [
                'referenceTransaction' => $donneesPaiement['referenceTransaction'],
                'recu' => $donneesPaiement['simulation']['accepte'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $confirmation = $reponseConfirmation->toArray();

        $vente = $this->em()->getRepository(Vente::class)->find($confirmation['vente']);
        self::assertInstanceOf(Vente::class, $vente);

        return ['vente' => $vente, 'email' => $email];
    }

    private function creerFactureAcquitteePour(Vente $vente): void
    {
        $em = $this->em();
        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
        self::assertInstanceOf(ProfilExploitant::class, $profil);
        $taux20 = $em->getRepository(TauxTva::class)->findOneBy(['libelle' => 'Taux normal 20 %']);
        self::assertInstanceOf(TauxTva::class, $taux20);
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);

        $destinataire = new DestinataireFacturation();
        $destinataire->setType(TypeDestinataire::Particulier)->setNom('Martin')->setPrenom('Alice')->setAdresse([]);

        $facture = new Facture();
        $facture->setNumero('FA-TEST-EBILLET-0001')
            ->setNature(NatureFacture::Facture)
            ->setOrigine(OrigineFacture::TicketEncaisse)
            ->setVenteOrigine($vente)
            ->setProfilExploitant($profil)
            ->setEtablissement($etabA)
            ->setDestinataire($destinataire)
            ->setStatut(StatutFacture::Acquittee)
            ->setDateEmission(new \DateTimeImmutable())
            ->setMentionAcquittee(true)
            ->setCreePar($admin);

        $ligne = new LigneFacture();
        $ligne->setDesignation('Billet boutique')->setQuantite(1)->setPrixUnitaireHT('10.00')->setTauxTva($taux20);
        $facture->addLigne($ligne);
        $facture->recalculerTotaux();

        $em->persist($facture);
        $em->flush();
    }
}
