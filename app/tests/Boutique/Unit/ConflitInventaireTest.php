<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SessionClient;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Enum\TypeSessionClient;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\ConfirmerCommandeHandler;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\Vente;

/**
 * §0 décision n°8 du plan (survente vs « aucun remboursement automatique », spec §8 cas limite
 * point 7) : si le stock est épuisé entre l'initiation et le retour de paiement (fenêtre résiduelle),
 * aucun avoir automatique n'est déclenché — une `DemandeRemboursement(origineAutomatique=true)` est
 * créée à la place.
 */
final class ConflitInventaireTest extends BoutiqueApiTestCase
{
    public function testConflitDInventaireResiduelCreeUneDemandeAutomatiqueJamaisUnAvoir(): void
    {
        static::createClient();
        $handler = static::getContainer()->get(ConfirmerCommandeHandler::class);

        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        $vitrine = $this->entite(Vitrine::class, ['etablissement' => $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM])]);

        $em = $this->em();
        $session = (new SessionClient())->setToken(PanierProprietaireGuard::hacher('t'))->setType(TypeSessionClient::Invite)->setEtablissement($vitrine->getEtablissement());
        $em->persist($session);
        $panier = (new PanierEnLigne())->setVitrine($vitrine)->setSessionClient($session)->setEtablissement($vitrine->getEtablissement())
            ->setContactConnu('conflit@example.test')->setConsentementRgpdHorodatage(new \DateTimeImmutable());
        $em->persist($panier);
        $ligne = (new LignePanierEnLigne())->setProduit($produit)->setQuantite(1)->setExpirationA($panier->getDateExpiration());
        $panier->addLigne($ligne);
        $em->persist($ligne);
        $em->flush();

        $vente = $handler->creerOuRecupererVente($panier);
        self::assertInstanceOf(Vente::class, $vente);

        // Simule la fenêtre résiduelle : le stock a été entièrement consommé par une autre vente
        // entre l'initiation et le retour de paiement.
        $stock = $produit->getStock();
        if ($stock === null) {
            $stock = new \App\Offre\Entity\Stock();
            $produit->setStock($stock);
        }
        $stock->setDisponibilite(0);
        $em->persist($stock);
        $em->flush();

        $conflit = $handler->confirmerApresPaiementReussi($panier, $vente, 'payfip', 'REF-CONFLIT-01');

        self::assertTrue($conflit, 'Décision n°8b : conflit d\'inventaire détecté au retour de paiement.');
        $demandes = $em->getRepository(DemandeRemboursement::class)->findAll();
        self::assertCount(1, $demandes);
        self::assertTrue($demandes[0]->isOrigineAutomatique());
        self::assertSame('recue', $demandes[0]->getStatut()->value);

        $avoirs = $em->getRepository(Avoir::class)->findAll();
        self::assertCount(0, $avoirs, 'RG-M3-15 : jamais d\'avoir automatique, même en cas de conflit résiduel.');
    }
}
