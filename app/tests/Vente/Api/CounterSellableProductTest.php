<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Audit\Entity\EntreeAudit;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\StatutProduit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\LigneVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La caisse ne vend que ce qui est vendable au guichet : produit PUBLIÉ, portant le canal GUICHET,
 * et commercialisé sur le site de la caisse (D92 : aucun site = socle, vendu partout).
 *
 * Mesuré le 06/10/2026 : un brouillon, un archivé et un produit du seul site B passaient tous en
 * 201 sur la caisse du site A, avec une ligne créée. Les fixtures de caisse étant en brouillon, la
 * suite validait ce défaut sans le savoir.
 *
 * Chaque refus vérifie AUSSI qu'aucune ligne n'a été écrite : un 422 rendu après l'écriture
 * laisserait la ligne en base.
 */
final class CounterSellableProductTest extends VenteApiTestCase
{
    public function testPublishedProductOfTheSiteIsSold(): void
    {
        $produit = $this->creerProduit('CSP-TEMOIN', StatutProduit::Publie, ['guichet'], [SocleFixtures::ETAB_A_NOM]);

        [$code, $lignes] = $this->ajouterSurCaisseA($produit);

        self::assertSame(201, $code, 'Témoin : un produit publié, au guichet, du site A, se vend sur la caisse du site A.');
        self::assertSame(1, $lignes);
    }

    public function testDraftProductIsRefused(): void
    {
        $produit = $this->creerProduit('CSP-BROUILLON', StatutProduit::Brouillon, ['guichet'], [SocleFixtures::ETAB_A_NOM]);

        [$code, $lignes, $message] = $this->ajouterSurCaisseA($produit);

        self::assertSame(422, $code);
        self::assertSame(0, $lignes, 'Aucune ligne ne doit être écrite pour un brouillon.');
        self::assertStringContainsString('pas encore en vente', $message);
    }

    public function testArchivedProductIsRefused(): void
    {
        $produit = $this->creerProduit('CSP-ARCHIVE', StatutProduit::Archive, ['guichet'], [SocleFixtures::ETAB_A_NOM]);

        [$code, $lignes, $message] = $this->ajouterSurCaisseA($produit);

        self::assertSame(422, $code);
        self::assertSame(0, $lignes, 'Aucune ligne ne doit être écrite pour un produit archivé.');
        self::assertStringContainsString('plus en vente', $message);
    }

    /**
     * D3 — hors de son site, le produit n'existe pas ici : 404, et une réponse INDISCERNABLE de celle
     * d'un UUID inconnu (le témoin). Un 422 « pas vendu sur ce site » confirmait son existence ailleurs.
     */
    public function testProductOfAnotherSiteOnlyIsNotFoundLikeAnUnknownProduct(): void
    {
        $produit = $this->creerProduit('CSP-SITE-B', StatutProduit::Publie, ['guichet'], [SocleFixtures::ETAB_B_NOM]);

        // Les deux demandes sur la MÊME vente : seule la référence du produit diffère.
        [$code, $lignes, , $corps, $codeInconnu, $corpsInconnu] = $this->ajouterSurCaisseA($produit, (string) Uuid::v4());

        self::assertSame(404, $codeInconnu, 'Témoin : un produit inconnu rend 404.');
        self::assertSame(404, $code);
        self::assertSame(0, $lignes, 'Aucune ligne ne doit être écrite pour un produit d\'un autre site.');
        self::assertSame(
            $this->sansIdentifiant($corpsInconnu),
            $this->sansIdentifiant($corps),
            'La réponse doit être identique à celle d\'un produit inexistant (D3).',
        );
    }

    /** D92 — aucun site = socle partagé : vendu partout. Le cas qui démasque un contrôle trop large. */
    public function testSharedBaseProductWithoutSiteIsSold(): void
    {
        $produit = $this->creerProduit('CSP-SOCLE', StatutProduit::Publie, ['guichet'], []);

        [$code, $lignes] = $this->ajouterSurCaisseA($produit);

        self::assertSame(201, $code, 'D92 : un produit sans site est du socle, vendu sur toutes les caisses.');
        self::assertSame(1, $lignes);
    }

    public function testProductWithoutCounterChannelIsRefused(): void
    {
        $produit = $this->creerProduit('CSP-EN-LIGNE', StatutProduit::Publie, ['en_ligne'], [SocleFixtures::ETAB_A_NOM]);

        [$code, $lignes, $message] = $this->ajouterSurCaisseA($produit);

        self::assertSame(422, $code);
        self::assertSame(0, $lignes, 'Aucune ligne ne doit être écrite pour un produit hors canal guichet.');
        self::assertStringContainsString('pas vendu au guichet', $message);
    }

    /**
     * Synchro hors ligne : la vente a DÉJÀ eu lieu, l'argent est dans le tiroir. La refuser la
     * perdrait (la quarantaine n'est rendue que dans la réponse, rien ne la persiste). Elle est donc
     * enregistrée, et l'écart est tracé : dans la réponse ET au journal d'audit.
     */
    public function testOfflineSaleOfUnsellableProductIsKeptAndTraced(): void
    {
        $produit = $this->creerProduit('CSP-HL-BROUILLON', StatutProduit::Brouillon, ['guichet'], [SocleFixtures::ETAB_A_NOM]);

        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $cle = (string) Uuid::v4();
        $reponse = $client->request('POST', '/api/synchro/operations', $entete + ['json' => [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [[
                'cleIdempotence' => $cle,
                'sequenceLocale' => 1,
                'lignes' => [[
                    'produit' => '/api/produits/' . $produit,
                    'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                    'quantite' => 1,
                ]],
                'paiements' => [['moyen' => 'especes', 'montant' => '7.00']],
            ]],
        ]])->toArray(false);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $reponse['inseres'], 'La vente déjà encaissée hors ligne ne doit pas être perdue.');
        self::assertSame([], $reponse['quarantaine']);
        self::assertCount(1, $reponse['anomalies'] ?? [], 'L\'écart doit être signalé dans la réponse.');
        self::assertSame($cle, $reponse['anomalies'][0]['cle']);
        self::assertStringContainsString('pas encore en vente', $reponse['anomalies'][0]['raison']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $traces = $em->getRepository(EntreeAudit::class)->findBy(['action' => 'vente.hors_ligne.produit_non_vendable']);
        self::assertCount(1, $traces, 'L\'écart doit rester tracé au journal d\'audit.');
        self::assertSame($reponse['inseres'][0], $traces[0]->getCibleId());
    }

    /**
     * Synchro hors ligne, produit d'un AUTRE site : refusé quand même. Le catalogue local de la caisse
     * n'a jamais pu le contenir ; l'accepter écrirait sur un ticket du site A le libellé et le prix
     * du catalogue du site B. La vente part en quarantaine, aucune ligne n'est écrite.
     */
    public function testOfflineSaleOfAnotherSiteProductIsQuarantined(): void
    {
        $produit = $this->creerProduit('CSP-HL-SITE-B', StatutProduit::Publie, ['guichet'], [SocleFixtures::ETAB_B_NOM]);

        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $cle = (string) Uuid::v4();
        $reponse = $client->request('POST', '/api/synchro/operations', $entete + ['json' => [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [[
                'cleIdempotence' => $cle,
                'sequenceLocale' => 1,
                'lignes' => [[
                    'produit' => '/api/produits/' . $produit,
                    'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                    'quantite' => 1,
                ]],
                'paiements' => [['moyen' => 'especes', 'montant' => '7.00']],
            ]],
        ]])->toArray(false);

        self::assertResponseIsSuccessful();
        self::assertSame([], $reponse['inseres']);
        self::assertCount(1, $reponse['quarantaine']);
        self::assertSame($cle, $reponse['quarantaine'][0]['cle']);
        // Même motif qu'un produit inconnu : la quarantaine ne révèle pas non plus le catalogue de B.
        self::assertSame('Produit introuvable.', $reponse['quarantaine'][0]['raison']);
        self::assertSame(0, $this->lignesDuProduit($produit));
    }

    /**
     * Le corps d'erreur, sans ce qui varie d'une requête à l'autre par construction :
     * - l'identifiant demandé (absent du corps aujourd'hui ; retiré quand même, pour comparer la FORME) ;
     * - `trace`, servie seulement en mode debug : ses frames CÔTÉ SERVEUR sont identiques (même ligne
     *   levée), seules diffèrent les lignes du fichier de test qui a émis la requête. La production
     *   ne la sert pas ; la garder ferait échouer le test sur une différence qu'aucun client ne voit.
     */
    private function sansIdentifiant(string $corps): string
    {
        $json = json_decode($corps, true);
        self::assertIsArray($json, 'Le corps d\'erreur doit être du JSON.');
        unset($json['trace']);

        return (string) preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
            '<uuid>',
            (string) json_encode($json, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: string, 4?: int, 5?: string} statut HTTP, lignes du
     *         produit en base, message d'erreur, corps brut ; puis statut et corps du témoin éventuel
     */
    private function ajouterSurCaisseA(string $produitId, ?string $temoinId = null): array
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);

        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $produitId,
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $code = $reponse->getStatusCode();
        $brut = $reponse->getContent(false);
        $corps = $reponse->toArray(false);
        $resultat = [$code, $this->lignesDuProduit($produitId), (string) ($corps['detail'] ?? $corps['hydra:description'] ?? ''), $brut];

        if ($temoinId !== null) {
            $temoin = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
                'json' => [
                    'produit' => '/api/produits/' . $temoinId,
                    'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                    'quantite' => 1,
                ],
            ]);
            $resultat[] = $temoin->getStatusCode();
            $resultat[] = $temoin->getContent(false);
        }

        return $resultat;
    }

    private function lignesDuProduit(string $produitId): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return \count($em->getRepository(LigneVente::class)->findBy(['produit' => Uuid::fromString($produitId)]));
    }

    /**
     * @param list<string> $canaux
     * @param list<string> $sites  noms d'établissements ; vide = socle (D92)
     */
    private function creerProduit(string $code, StatutProduit $statut, array $canaux, array $sites): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $type = $em->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        $tarif = $em->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        $saison = $em->getRepository(Saison::class)->findOneBy(['nom' => OffreFixtures::SAISON]);
        self::assertInstanceOf(TypeProduit::class, $type);
        self::assertInstanceOf(TypeTarif::class, $tarif);
        self::assertInstanceOf(Saison::class, $saison);

        $produit = (new Produit())
            ->setType($type)
            ->setLibelle(['fr' => $code])
            ->setLibelleRecherche($code)
            ->setCode($code)
            ->setCanaux($canaux)
            ->setStatut($statut);
        foreach ($sites as $nom) {
            $site = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
            self::assertInstanceOf(Etablissement::class, $site);
            $produit->addEtablissement($site);
        }
        $grille = (new GrilleTarifaire())->setProduit($produit)->setTypeTarif($tarif)->setSaison($saison)->setPrix('7.00');
        $produit->addGrille($grille);
        $em->persist($produit);
        $em->persist($grille);
        $em->flush();

        return (string) $produit->getId();
    }
}
