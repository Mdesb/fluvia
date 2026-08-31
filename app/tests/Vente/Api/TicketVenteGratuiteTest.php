<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Caisse\Entity\PointDeVente;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\Vente;
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

    /**
     * UNE VENTE GRATUITE VALIDEE EN SESSION N'EST PAS MARQUEE « IMPRIMEE ».
     *
     * ⚠ CE TEST AURAIT ATTRAPE MON PROPRE DEMI-CORRECTIF. La regle du seuil etait ecrite DEUX FOIS :
     * dans `TicketProcessor` et dans `ValiderVenteService`. J'ai corrige la premiere et laisse la
     * seconde, si bien qu'une vente a 0 € en session restait marquee imprimee -- pour un document
     * que le meme depot refusait d'editer. La reedition suivante aurait annonce un DUPLICATA d'un
     * ticket qui n'a jamais existe.
     *
     * Trouve par une session d'ecran en branchant la caisse, pas en relisant le code. Les deux
     * appelants passent desormais par `TicketPrintingPolicy`.
     */
    public function testUneVenteGratuiteEnSessionNEstPasMarqueeImprimee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // ⚠ LE SEUIL PAR DEFAUT, ET C'EST LA TOUT L'INTERET.
        //
        // Les fixtures posent 20,00 € : avec ce seuil, `0 >= 2000` est faux et une vente gratuite
        // n'etait de toute facon pas marquee imprimee -- le test restait vert meme avec l'ancienne
        // regle fautive. Or le defaut mord sur un etablissement NEUF, dont le seuil vaut 0,00 par
        // defaut parce que personne ne configure ce champ : `0 >= 0` y est vrai.
        //
        // On reproduit donc la configuration reelle des nouveaux sites, pas celle du jeu d'essai.
        $em = static::getContainer()->get('doctrine')->getManager();
        $pdv = $em->getRepository(PointDeVente::class)->find($this->idPointDeVente());
        self::assertNotNull($pdv);
        $pdv->setSeuilImpression('0.00');
        $em->flush();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $this->ajouterProduitGratuit($client, $entete, $vente['id']);

        $validee = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []])->toArray();
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));
        self::assertSame('validee', $validee['statut'], 'témoin : la vente gratuite se valide bien');

        // ⚠ ON LIT L'ÉTAT EN BASE, ET AVANT TOUTE DEMANDE DE TICKET.
        //
        // Première version de ce test : je vérifiais `duplicata` sur la réponse du ticket. Éprouvé
        // en rétablissant l'ancienne règle recopiée, il restait VERT — l'assertion ne distinguait
        // pas les deux états. Et demander le ticket POSE le drapeau, ce qui effacerait la différence
        // qu'on cherche à mesurer.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $enBase = $em->getRepository(Vente::class)->find($vente['id']);
        self::assertNotNull($enBase);

        self::assertFalse(
            $enBase->isImprime(),
            'une vente entièrement gratuite ne doit pas être marquée imprimée : le ticket n’est pas édité',
        );
    }

    /**
     * LE RENVOI PAR COURRIEL OU SMS REFUSE BRUYAMMENT, AU LIEU DE DIRE « FAIT ».
     *
     * ⚠ Il n'existe dans tout le module ni expediteur, ni passerelle SMS, ni evenement : le mode
     * rendait pourtant `renvoye: true`. Une reponse qui dit « fait » pour un geste dont le code
     * n'existe pas est le pire de ce qu'on traque -- l'exploitant coche, ferme l'ecran, et le client
     * n'a jamais rien recu.
     *
     * Et ce n'est pas le transport nul : un `MAILER_DSN` correct ne changerait rien.
     */
    public function testLeRenvoiRefuseTantQuAucunEnvoiNExiste(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterProduitGratuit($client, $entete, $vente['id']);

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/ticket', $entete + [
            'json' => ['mode' => 'renvoyer', 'canal' => 'email'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'pas encore implémenté',
            (string) $client->getResponse()->getContent(false),
            'le refus doit NOMMER ce qui manque, sinon on cherchera du cote de la configuration',
        );
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

        // ⚠ PAS N'IMPORTE QUEL TYPE. `findOneBy([])` rendait le premier venu, et celui-la etait
        // NOMINATIF : en session, l'ajout exigeait un beneficiaire (RG-M2-04) et le test echouait
        // pour une raison sans rapport avec ce qu'il mesure. On reprend le type du produit d'entree
        // des fixtures, qui se vend en caisse dans tout le reste de la suite.
        $modele = $em->getRepository(Produit::class)->find($this->idProduit(OffreFixtures::PRODUIT_ENTREE));
        self::assertInstanceOf(Produit::class, $modele);
        $type = $modele->getType();
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
