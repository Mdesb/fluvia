<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\ConfirmerCommandeHandler;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Revue de sécurité — faille bloquante (#1) : un produit rattaché à un autre établissement que celui
 * du panier ne doit jamais pouvoir être acheté via la vitrine tierce (cloisonnement établissement).
 */
final class CloisonnementProduitEtablissementTest extends BoutiqueApiTestCase
{
    public function testAjoutAuPanierDUnProduitDUnAutreEtablissementEstRefuse(): void
    {
        $produitEtabB = $this->creerProduitPublieSurEtablissement(SocleFixtures::ETAB_B_NOM, 'PRD-BOU-CROISE-01');

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produitEtabB->getId(), 'quantite' => 1],
        ]);
        self::assertResponseStatusCodeSame(422, 'Un produit d\'un autre établissement ne doit jamais être ajoutable au panier.');
    }

    public function testConfirmerCommandeHandlerRefuseEnDefenseEnProfondeurUneLigneCroisee(): void
    {
        static::createClient();
        /** @var ConfirmerCommandeHandler $handler */
        $handler = static::getContainer()->get(ConfirmerCommandeHandler::class);

        $produitEtabB = $this->creerProduitPublieSurEtablissement(SocleFixtures::ETAB_B_NOM, 'PRD-BOU-CROISE-02');

        // Construit un panier sur l'établissement A avec une ligne rattachée au produit de
        // l'établissement B, en contournant volontairement `AjouterLignePanierProcessor` (persistance
        // directe) pour vérifier la défense en profondeur de `ConfirmerCommandeHandler`.
        $vitrineA = $this->entite(\App\Boutique\Entity\Vitrine::class, ['etablissement' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])]);
        $em = $this->em();
        $session = (new \App\Boutique\Entity\SessionClient())
            ->setToken(PanierProprietaireGuard::hacher('t-cloisonnement'))
            ->setType(\App\Boutique\Enum\TypeSessionClient::Invite)
            ->setEtablissement($vitrineA->getEtablissement());
        $em->persist($session);
        $panier = (new \App\Boutique\Entity\PanierEnLigne())
            ->setVitrine($vitrineA)
            ->setSessionClient($session)
            ->setEtablissement($vitrineA->getEtablissement())
            ->setContactConnu('cloisonnement@example.test')
            ->setConsentementRgpdHorodatage(new \DateTimeImmutable());
        $em->persist($panier);
        $ligne = (new \App\Boutique\Entity\LignePanierEnLigne())
            ->setProduit($produitEtabB)->setQuantite(1)->setExpirationA($panier->getDateExpiration());
        $panier->addLigne($ligne);
        $em->persist($ligne);
        $em->flush();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException::class);
        $handler->creerOuRecupererVente($panier);
    }

    private function creerProduitPublieSurEtablissement(string $nomEtablissement, string $code): Produit
    {
        $etablissement = $this->entite(Etablissement::class, ['nom' => $nomEtablissement]);
        $typeEntree = $this->entite(TypeProduit::class, ['code' => OffreFixtures::TYPE_ENTREE]);
        $tarifPlein = $this->entite(TypeTarif::class, ['nom' => OffreFixtures::TARIF_PLEIN]);
        $saison = $this->entite(Saison::class, ['nom' => OffreFixtures::SAISON]);

        $produit = (new Produit())->setType($typeEntree)
            ->setLibelle(['fr' => 'Produit croisé'])->setLibelleRecherche('Produit croisé')
            ->setCode($code)->setCanaux(['guichet', 'en_ligne'])->setStatut(StatutProduit::Publie);
        $produit->addEtablissement($etablissement);
        $this->em()->persist($produit);
        $produit->addGrille((new GrilleTarifaire())->setProduit($produit)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('9.00'));
        $this->em()->persist($produit->getGrilles()->last());
        $this->em()->flush();

        return $produit;
    }
}
