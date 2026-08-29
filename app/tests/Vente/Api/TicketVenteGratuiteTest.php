<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use ApiPlatform\Symfony\Bundle\Test\Client;

/**
 * UNE VENTE ENTIÈREMENT GRATUITE NE SORT PAS DE TICKET.
 *
 * Demande de Maxime : « Lorsqu'un produit gratuit ou plusieurs produits gratuits sont vendus seuls,
 * il ne faut pas qu'il y ait des tickets de caisse qui sortent. Par contre, c'est bien entendu
 * comptabilisé dans le logiciel. »
 *
 * ⚠ LE DÉFAUT VENAIT D'UNE COMPARAISON, PAS D'UN RÉGLAGE. Le seuil d'impression par défaut d'un
 * point de vente vaut 0,00, et la règle était `total >= seuil`. Une vente à 0 € donnait donc
 * `0 >= 0`, VRAI : elle était déclarée au-dessus du seuil et le ticket partait. Une entrée offerte,
 * un badge de courtoisie, un lot d'invitations faisaient sortir un ticket à zéro que le caissier
 * jette.
 *
 * ⚠ « VENDUS SEULS » EST LE MOT IMPORTANT, et c'est le second test. Un produit gratuit accompagné
 * d'un produit payant donne un total non nul : le ticket sort normalement, et il le doit — le client
 * a payé quelque chose. Le critère est le TOTAL de la vente, jamais la présence d'une ligne à zéro.
 */
final class TicketVenteGratuiteTest extends VenteApiTestCase
{
    /**
     * ZÉRO EURO : NI IMPRESSION AUTOMATIQUE, NI PROPOSITION DE RENVOI.
     *
     * Proposer d'envoyer par SMS un ticket à 0 € serait le même bruit déplacé sur un autre canal.
     */
    public function testUneVenteEntierementGratuiteNImprimePas(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterProduitGratuit($client, $entete, $vente['id']);

        $ticket = $this->demanderTicket($client, $entete, $vente['id']);

        self::assertSame('0.00', $ticket['total'], 'témoin : la vente est bien à zéro, sinon ce test ne mesure rien');
        self::assertTrue($ticket['venteGratuite'], 'le serveur doit dire POURQUOI il n’imprime pas');
        self::assertFalse($ticket['impressionAutomatique'], 'aucun ticket ne sort d’une vente entièrement gratuite');
        self::assertFalse($ticket['renvoiPropose'], 'et on ne propose pas non plus de l’envoyer');
    }

    /**
     * LA VENTE RESTE ENREGISTRÉE : C'EST LE PAPIER QU'ON NE SORT PAS, PAS L'OPÉRATION QU'ON EFFACE.
     *
     * Maxime l'a précisé lui-même — « c'est bien entendu comptabilisé ». Sans cette assertion, une
     * implémentation qui refuserait carrément la vente gratuite passerait le premier test.
     */
    public function testLaVenteGratuiteResteEnregistreeAvecSesLignes(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterProduitGratuit($client, $entete, $vente['id']);

        $ticket = $this->demanderTicket($client, $entete, $vente['id']);

        self::assertCount(1, $ticket['lignes'], 'la ligne existe et reste lisible : la vente est comptabilisée');
    }

    /**
     * UN PRODUIT GRATUIT ACCOMPAGNÉ D'UN PRODUIT PAYANT : LE TICKET SORT.
     *
     * ⚠ C'est le témoin de la paire. Sans lui, une règle qui n'imprimerait JAMAIS — la régression
     * exacte qu'un « ne pas imprimer » trop large introduirait — passerait les deux tests précédents
     * avec les félicitations.
     */
    public function testUnProduitGratuitAccompagneDUnPayantImprimeNormalement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterProduitGratuit($client, $entete, $vente['id']);

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $ticket = $this->demanderTicket($client, $entete, $vente['id']);

        self::assertFalse($ticket['venteGratuite'], 'le total n’est plus nul : la vente n’est pas gratuite');
        self::assertTrue($ticket['impressionAutomatique'], 'le client a payé quelque chose, le ticket sort');
    }

    // ── Montage ──────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function demanderTicket(Client $client, array $entete, string $venteId): array
    {
        $ticket = $client->request('POST', '/api/ventes/' . $venteId . '/ticket', $entete + [
            'json' => ['mode' => 'imprimer'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $ticket;
    }

    /** @param array<string, mixed> $entete */
    private function ajouterProduitGratuit(Client $client, array $entete, string $venteId): void
    {
        $produit = $this->produitGratuit();

        $client->request('POST', '/api/ventes/' . $venteId . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produit->getId(),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));
    }

    /**
     * Un produit réellement à 0,00, et non une vente vide.
     *
     * Une vente sans ligne aurait aussi un total nul et emprunterait la même branche — mais elle ne
     * dit rien du cas que Maxime décrit. Un test qui prouve la règle par un raccourci prouve le
     * raccourci.
     */
    private function produitGratuit(): Produit
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $existant = $em->getRepository(Produit::class)->findOneBy(['code' => 'PRD-INVITATION']);
        if ($existant instanceof Produit) {
            return $existant;
        }

        $type = $em->getRepository(TypeProduit::class)->findOneBy([]);
        self::assertInstanceOf(TypeProduit::class, $type);
        $tarifPlein = $em->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        self::assertInstanceOf(TypeTarif::class, $tarifPlein);
        $saison = $em->getRepository(Saison::class)->findOneBy(['actif' => true]);
        self::assertInstanceOf(Saison::class, $saison, 'témoin : sans saison active aucun tarif ne se résout');

        $etablissement = $em->getRepository(Etablissement::class)->find($this->idEtablissement('Piscine A'));
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $produit = (new Produit())
            ->setType($type)
            ->setLibelle(['fr' => 'Invitation'])
            ->setLibelleRecherche('Invitation')
            ->setCode('PRD-INVITATION')
            ->setCanaux(['guichet'])
            ->setTauxTva('10.00')
            ->setStatut(StatutProduit::Publie);
        $produit->addEtablissement($etablissement);

        // ⚠ La grille se persiste À PART : `Produit#grilles` ne cascade pas.
        $grille = (new GrilleTarifaire())->setProduit($produit)->setTypeTarif($tarifPlein)->setSaison($saison)->setPrix('0.00');
        $produit->addGrille($grille);

        $em->persist($produit);
        $em->persist($grille);
        $em->flush();

        return $produit;
    }
}
