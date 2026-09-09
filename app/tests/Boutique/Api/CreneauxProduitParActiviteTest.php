<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Offre\Entity\Produit;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * `GET /boutique/produits/{id}/creneaux` — le produit désigne ses créneaux par l'ACTIVITÉ qui le
 * référence (arbitrage de Maxime, 07/09), et le périmètre de la vitrine borne ce qu'elle montre.
 *
 * ⚠ CHAQUE CAS EST ÉCRIT PAR CONTRASTE, ET C'EST DÉLIBÉRÉ. Une liste vide est ce que rend aussi une
 * requête cassée : un test qui n'affirmerait qu'un `[]` passerait encore le jour où la jointure ne
 * ramène plus rien du tout. Chaque assertion d'absence est donc précédée, dans le même test, d'une
 * assertion de présence sur le même appel — le témoin positif qui dit que l'instrument voit.
 */
final class CreneauxProduitParActiviteTest extends BoutiqueApiTestCase
{
    public function testLesCreneauxViennentDeLActiviteQuiReferenceLeProduit(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        $route = '/api/boutique/produits/' . (string) $produit->getId() . '/creneaux';

        $client = static::createClient();
        $client->disableReboot();

        $avant = $client->request('GET', $route)->toArray();
        self::assertNotEmpty($avant['creneaux'], 'Témoin positif : l\'activité de fixture porte bien un créneau à venir.');

        // On coupe le SEUL lien qui doit compter. Si la liste ne se vide pas, c'est qu'un autre
        // chemin alimente encore la boutique — et le test suivant qui « passe » ne mesurerait rien.
        $activite = $this->em()->getRepository(Activite::class)
            ->findOneBy(['produitTarifReference' => $produit]);
        self::assertInstanceOf(Activite::class, $activite);
        $activite->setProduitTarifReference(null);
        $this->em()->flush();

        $apres = $client->request('GET', $route)->toArray();
        self::assertSame([], $apres['creneaux'], 'Le lien produit↔créneaux passe par l\'activité, et par elle seule.');
    }

    public function testUneOccurrenceEnAttenteDArbitrageNEstPasProposeeEnLigne(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        $route = '/api/boutique/produits/' . (string) $produit->getId() . '/creneaux';

        $client = static::createClient();
        $client->disableReboot();

        $activite = $this->em()->getRepository(Activite::class)
            ->findOneBy(['produitTarifReference' => $produit]);
        self::assertInstanceOf(Activite::class, $activite);

        $modele = $this->em()->getRepository(Creneau::class)->findOneBy(['activite' => $activite]);
        self::assertInstanceOf(Creneau::class, $modele);

        // Deux créneaux jumeaux à des heures différentes : seul le drapeau les distingue. C'est le
        // contraste qui prouve l'exclusion — un test qui n'aurait posé que le créneau arbitré
        // n'aurait pas su dire si l'absence venait du drapeau ou de la date.
        $debut = $modele->getDebut()->modify('+1 day');
        foreach ([[$debut, false], [$debut->modify('+2 hours'), true]] as [$quand, $arbitrage]) {
            $creneau = (new Creneau())
                ->setRessource($modele->getRessource())
                ->setActivite($activite)
                ->setDebut($quand)
                ->setFin($quand->modify('+1 hour'))
                ->setCapacite(10)
                ->setEtablissement($modele->getEtablissement())
                ->setEnAttenteArbitrage($arbitrage);
            $this->em()->persist($creneau);
        }
        $this->em()->flush();

        $rendus = $client->request('GET', $route)->toArray()['creneaux'];
        $horaires = array_column($rendus, 'debut');

        self::assertContains(
            $debut->format(DATE_ATOM),
            $horaires,
            'Témoin positif : le jumeau non arbitré est bien proposé.',
        );
        self::assertNotContains(
            $debut->modify('+2 hours')->format(DATE_ATOM),
            $horaires,
            'ReserverProcessor refuse un créneau en attente d\'arbitrage : le vendre en ligne promettrait une place que le serveur refuse.',
        );
    }

    public function testUneVitrineNeMontreQueLesHorairesDeSonEtablissement(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        $route = '/api/boutique/produits/' . (string) $produit->getId() . '/creneaux';

        $client = static::createClient();

        // Le produit n'est diffusé que sur l'établissement A (fixture).
        $chezA = $client->request('GET', $route . '?vitrine=' . $this->idVitrineA())->toArray();
        self::assertNotEmpty($chezA['creneaux'], 'Témoin positif : la vitrine du vendeur voit ses horaires.');

        $chezB = $client->request('GET', $route . '?vitrine=' . $this->idVitrineB())->toArray();
        self::assertSame(
            [],
            $chezB['creneaux'],
            'Une boutique ne montre pas les horaires programmés par un autre établissement.',
        );
    }

    public function testUneVitrineInconnueEstRefuseeAuLieuDElargirLePerimetre(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);

        $client = static::createClient();
        $client->request('GET', '/api/boutique/produits/' . (string) $produit->getId() . '/creneaux?vitrine=vitrine-qui-nexiste-pas');

        // Un repli silencieux sur « tous les établissements » ferait du paramètre une décoration :
        // il suffirait de l'écrire faux pour l'annuler.
        self::assertResponseStatusCodeSame(404);
    }
}
