<?php

declare(strict_types=1);

namespace App\Tests\OptionProduit\Api;

use App\DataFixtures\SocleFixtures;
use App\OptionProduit\Enum\ImpactOptionType;
use App\OptionProduit\Enum\ModeSelectionOption;
use App\Organisation\Entity\Etablissement;
use App\Tests\OptionProduit\OptionProduitApiTestCase;

/**
 * **Le prix d'une option, avant l'ajout au panier.**
 *
 * La plainte de Maxime était *« je ne comprends rien aux options produit »*, et la cause était écrite
 * dans le code : `OptionsDisponiblesProvider` déclare lui-même *« lecture seule, sans résolution de
 * prix »*. L'écran pouvait lister les options d'un produit et **pas dire ce qu'elles coûtent** — le
 * supplément ne se découvrait qu'après l'ajout au panier.
 *
 * **Le cas du pourcentage est celui qui interdisait toute solution côté écran** : il porte sur le prix
 * de base **résolu** (tarif × saison × quotient familial), que le navigateur ne connaît pas. Sans cet
 * endpoint, la caisse aurait réimplémenté `ImpactOptionType` — une seconde règle tarifaire, dans une
 * couche où personne ne voit la divergence.
 *
 * Le test central n'est donc pas « l'option a un prix » : c'est
 * `testLeMontantAnnonceEstCeluiQueLaCaisseFacture`, qui **confronte l'estimation à la ligne réellement
 * créée**.
 */
final class PrixOptionsAvantPanierTest extends OptionProduitApiTestCase
{
    /** **Le test qui porte la décision** : annoncé et facturé sont le même nombre, pourcentage compris. */
    public function testLeMontantAnnonceEstCeluiQueLaCaisseFacture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Affutage', ModeSelectionOption::Unique);
        $competition = $this->creerValeurOption($groupe, 'Competition', ImpactOptionType::Pourcentage, '10.00');
        $this->creerOptionProduit($produit, $groupe);

        $estimation = $client->request('GET', sprintf(
            '/api/produits/%s/tarif?typeTarif=%s&options[]=%s',
            $produit->getId(),
            $tarif->getId(),
            $competition->getId(),
        ), $entete)->toArray();
        self::assertResponseIsSuccessful();

        // 10 % de 20,00 EUR = 2,00 EUR — un nombre que l'ecran ne pouvait pas calculer seul.
        $valeur = $this->valeur($estimation, (string) $competition->getId());
        self::assertSame('2.00', $valeur['montantParUnite']);
        self::assertSame('22.00', $estimation['totalUnitaire']);

        // Et la caisse facture exactement cela.
        $ligne = $this->ligneFacturee($client, $entete, $produit, $tarif, [(string) $competition->getId()]);
        self::assertSame('2.00', $ligne['impactOptionsUnitaire']);
        self::assertSame(
            $estimation['totalUnitaire'],
            $ligne['montantLigne'],
            'Le client entendrait un supplement et en paierait un autre.',
        );
    }

    /**
     * `montantParUnite` porte son nom : la quantité multiplie, et le serveur le dit.
     *
     * `claude-H` a relevé l'ambiguïté du nom que j'avais proposé — *« unitaire par rapport à quoi ? »*
     * Trois patins avec un affûtage à 5 € font-ils +5 ou +15 sur la ligne ? Elle le pensait sans le
     * savoir, et c'est la forme exacte de la faute qu'elle avait corrigée deux fois le même jour : un
     * nom presque juste, une supposition raisonnable, un chiffre faux sur le document que le client
     * emporte.
     */
    public function testLaQuantiteMultiplieEtLeServeurLeDit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Affutage');
        $standard = $this->creerValeurOption($groupe, 'Standard', ImpactOptionType::Montant, '5.00');
        $this->creerOptionProduit($produit, $groupe);

        $estimation = $client->request('GET', sprintf(
            '/api/produits/%s/tarif?typeTarif=%s&options[]=%s&quantite=3',
            $produit->getId(),
            $tarif->getId(),
            $standard->getId(),
        ), $entete)->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame('5.00', $this->valeur($estimation, (string) $standard->getId())['montantParUnite']);
        self::assertSame('25.00', $estimation['totalUnitaire'], 'Par unite : 20 + 5.');
        self::assertSame('75.00', $estimation['totalLigne'], 'Pour trois : (20 + 5) x 3.');
        self::assertSame(3, $estimation['quantite']);
    }

    /**
     * **Une option indisponible est rendue, avec sa raison** — et non omise.
     *
     * `claude-H` a nuancé sa propre règle pour ce cas : *la question n'est pas si l'action est
     * possible, c'est si l'utilisateur a une raison de la chercher.* Un client qui réclame nommément
     * une option envoie le caissier fouiller le paramétrage s'il ne la trouve pas. **Une absence sans
     * explication est une énigme ; une présence expliquée est une réponse.**
     */
    public function testUneOptionIndisponibleDitPourquoi(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $etabB = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);

        $groupe = $this->creerGroupeOption('Reserve a B');
        $valeur = $this->creerValeurOption($groupe, 'Exclusivite B', ImpactOptionType::Montant, '3.00');
        $this->creerOptionProduit($produit, $groupe, false, [$etabB]);

        $estimation = $client->request(
            'GET',
            sprintf('/api/produits/%s/tarif?typeTarif=%s', $produit->getId(), $tarif->getId()),
            $entete,
        )->toArray();
        self::assertResponseIsSuccessful();

        $lue = $this->valeur($estimation, (string) $valeur->getId());
        self::assertFalse($lue['disponible']);
        self::assertNotSame('', $lue['motif'], 'Une option grisee sans motif envoie chercher un droit manquant.');
        self::assertStringContainsString('etablissement', $lue['motif']);
    }

    /** Une option retenue mais indisponible n'entre pas dans le total : la caisse la refuserait. */
    public function testUneOptionIndisponibleRetenueNEntrePasDansLeTotal(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $etabB = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);

        $groupe = $this->creerGroupeOption('Reserve a B');
        $valeur = $this->creerValeurOption($groupe, 'Exclusivite B', ImpactOptionType::Montant, '3.00');
        $this->creerOptionProduit($produit, $groupe, false, [$etabB]);

        $estimation = $client->request('GET', sprintf(
            '/api/produits/%s/tarif?typeTarif=%s&options[]=%s',
            $produit->getId(),
            $tarif->getId(),
            $valeur->getId(),
        ), $entete)->toArray();

        self::assertSame('20.00', $estimation['totalUnitaire'], 'Annoncer un total que la vente ne produira pas est le defaut qu on ferme ici.');
    }

    /** Le groupe porte de quoi construire l'écran sans deviner : mode de sélection et obligation. */
    public function testLeGroupePorteDeQuoiConstruireLEcran(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        [$produit, $tarif] = $this->creerProduitBase('20.00');

        $groupe = $this->creerGroupeOption('Affutage', ModeSelectionOption::Unique);
        $this->creerValeurOption($groupe, 'Standard', ImpactOptionType::Montant, '5.00');
        $this->creerOptionProduit($produit, $groupe, true);

        $estimation = $client->request(
            'GET',
            sprintf('/api/produits/%s/tarif?typeTarif=%s', $produit->getId(), $tarif->getId()),
            $entete,
        )->toArray();

        $lu = $estimation['options'][0];
        self::assertSame('unique', $lu['modeSelection'], 'Bouton radio ou cases a cocher : l ecran ne doit pas deviner.');
        self::assertTrue($lu['obligatoire']);
        // `impactType` et `impactValeur` restent exposes a la demande de `claude-H` : ils ne servent
        // pas a calculer, ils servent a EXPLIQUER. « +10 % » repond a « pourquoi », « +1,00 EUR » non.
        self::assertSame('montant', $lu['valeurs'][0]['impactType']);
        self::assertSame('5.00', $lu['valeurs'][0]['impactValeur']);
    }

    /**
     * @param array<string, mixed> $estimation
     *
     * @return array<string, mixed>
     */
    private function valeur(array $estimation, string $valeurOptionId): array
    {
        foreach ($estimation['options'] as $groupe) {
            foreach ($groupe['valeurs'] as $valeur) {
                if ($valeur['valeurOption'] === $valeurOptionId) {
                    return $valeur;
                }
            }
        }

        self::fail(sprintf('Valeur d\'option %s absente de l\'estimation.', $valeurOptionId));
    }

    /**
     * @param array<string, mixed> $entete
     * @param list<string>         $options
     *
     * @return array<string, mixed>
     */
    private function ligneFacturee(object $client, array $entete, object $produit, object $tarif, array $options): array
    {
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
                'quantite' => 1,
                'options' => $options,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray()['lignes'][0];
    }
}
