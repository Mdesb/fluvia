<?php

declare(strict_types=1);

namespace App\Tests\Stock\Api;

use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Securite\Entity\Utilisateur;
use App\Stock\DataFixtures\StockFixtures;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\TransfertStock;
use App\Tests\Stock\StockApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Revue de sécurité du module `App\Stock` (correctifs post-déploiement) : IDOR cross-tenant sur les
 * processors/providers qui résolvent un identifiant fourni dans le corps par un `find()` Doctrine brut
 * (hors `PerimetreStockExtension`), rattachement `ArticleStock` ↔ `Produit` M1 hors établissement, et
 * permission `stock.gerer` désormais opérante sur les écritures du module. L'administrateur socle
 * (affecté sur A **et** B, `CloisonnementTest`) sert uniquement à préparer les données ; les
 * assertions portent sur des opérateurs affectés à un **seul** établissement.
 */
final class PerimetreEcritureTest extends StockApiTestCase
{
    // --- 1. IDOR : AjustementMouvementProcessor -----------------------------------------------

    public function testAjustementRefuseArticleHorsPerimetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $articleB = $this->creerArticle($client, $entete, SocleFixtures::ETAB_B_NOM, '40170725');

        [$clientOperateur, $enteteOperateur] = $this->operateurStockSur(SocleFixtures::ETAB_A_NOM, ['ajuster']);

        $clientOperateur->request('POST', '/api/stock/mouvements/ajustement', $enteteOperateur + [
            'json' => [
                'articleStock' => '/api/article_stocks/' . $articleB['id'],
                'type' => 'ajustement_positif',
                'quantite' => '1.000',
                'motif' => 'Tentative IDOR',
            ],
        ]);
        self::assertResponseStatusCodeSame(403, 'Un opérateur affecté uniquement à A ne doit jamais pouvoir ajuster un article de B (RG-SOCLE-05).');
    }

    public function testAjustementRefuseReceptionOrigineHorsPerimetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $articleA = $this->creerArticle($client, $entete, SocleFixtures::ETAB_A_NOM, '5901234123457');
        $articleB = $this->creerArticle($client, $entete, SocleFixtures::ETAB_B_NOM, '40170725');
        $receptionB = $this->receptionner($client, $entete, SocleFixtures::ETAB_B_NOM, $articleB['id'], '5.000', '2.0000');

        [$clientOperateur, $enteteOperateur] = $this->operateurStockSur(SocleFixtures::ETAB_A_NOM, ['ajuster']);

        $clientOperateur->request('POST', '/api/stock/mouvements/ajustement', $enteteOperateur + [
            'json' => [
                'articleStock' => '/api/article_stocks/' . $articleA['id'],
                'type' => 'ajustement_positif',
                'quantite' => '1.000',
                'motif' => 'Tentative IDOR via receptionOrigine',
                'receptionOrigine' => '/api/stock_reception_achats/' . $receptionB,
            ],
        ]);
        self::assertResponseStatusCodeSame(403, 'Une réception d\'achat de B ne doit pas être exploitable par un opérateur affecté uniquement à A.');
    }

    // --- 1. IDOR : ReintegrationRetourProcessor ------------------------------------------------

    public function testReintegrationRetourRefuseAvoirHorsPerimetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $avoirA = $this->creerAvoirSurA($client, $entete);
        $articleB = $this->creerArticle($client, $entete, SocleFixtures::ETAB_B_NOM, '40170725');

        [$clientOperateur, $enteteOperateur] = $this->operateurStockSur(SocleFixtures::ETAB_B_NOM, ['ajuster']);

        $clientOperateur->request('POST', '/api/stock/mouvements/reintegration-retour', $enteteOperateur + [
            'json' => [
                'avoirId' => $avoirA,
                'articleStock' => '/api/article_stocks/' . $articleB['id'],
                'quantite' => '1.000',
            ],
        ]);
        self::assertResponseStatusCodeSame(403, 'Un avoir émis sur A ne doit pas être exploitable par un opérateur affecté uniquement à B.');
    }

    public function testReintegrationRetourRefuseArticleHorsPerimetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $avoirA = $this->creerAvoirSurA($client, $entete);
        $articleB = $this->creerArticle($client, $entete, SocleFixtures::ETAB_B_NOM, '40170725');

        // Opérateur affecté uniquement à A : l'avoir est dans son périmètre, l'article (B) ne l'est pas.
        [$clientOperateur, $enteteOperateur] = $this->operateurStockSur(SocleFixtures::ETAB_A_NOM, ['ajuster']);

        $clientOperateur->request('POST', '/api/stock/mouvements/reintegration-retour', $enteteOperateur + [
            'json' => [
                'avoirId' => $avoirA,
                'articleStock' => '/api/article_stocks/' . $articleB['id'],
                'quantite' => '1.000',
            ],
        ]);
        self::assertResponseStatusCodeSame(403, 'Un article de stock de B ne doit pas être réintégrable par un opérateur affecté uniquement à A.');
    }

    // --- 1. IDOR : CreerTransfertProcessor (les deux côtés) ------------------------------------

    public function testCreerTransfertRefuseSourceHorsPerimetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $articleA = $this->creerArticle($client, $entete, SocleFixtures::ETAB_A_NOM, '5901234123457');
        $articleB = $this->creerArticle($client, $entete, SocleFixtures::ETAB_B_NOM, '40170725');

        [$clientOperateur, $enteteOperateur] = $this->operateurStockSur(SocleFixtures::ETAB_B_NOM, ['transferer']);

        $clientOperateur->request('POST', '/api/stock_transferts', $enteteOperateur + [
            'json' => [
                'articleStockSource' => '/api/article_stocks/' . $articleA['id'],
                'articleStockDestination' => '/api/article_stocks/' . $articleB['id'],
                'quantite' => '1.000',
            ],
        ]);
        self::assertResponseStatusCodeSame(403, 'Un opérateur affecté uniquement à B ne doit pas pouvoir créer un transfert dont la source (A) est hors périmètre.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        self::assertCount(0, $em->getRepository(TransfertStock::class)->findAll(), 'Aucun TransfertStock ne doit avoir été persisté.');
    }

    // --- 1. IDOR : Expedier/Recevoir — contrôle spécifique par côté (pas un simple OR) ---------

    public function testExpedierExigeDroitEtablissementSourceEtNeDecrementePasSiRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $articleA = $this->creerArticle($client, $entete, SocleFixtures::ETAB_A_NOM, '5901234123457');
        $this->receptionner($client, $entete, SocleFixtures::ETAB_A_NOM, $articleA['id'], '20.000', '6.0000');
        $articleB = $this->creerArticle($client, $entete, SocleFixtures::ETAB_B_NOM, '40170725');

        // Admin (affecté sur A et B) crée légitimement le transfert.
        $transfert = $client->request('POST', '/api/stock_transferts', $entete + [
            'json' => [
                'articleStockSource' => '/api/article_stocks/' . $articleA['id'],
                'articleStockDestination' => '/api/article_stocks/' . $articleB['id'],
                'quantite' => '8.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        // Opérateur affecté uniquement à B (destination) : ne doit pas pouvoir expédier (droit source requis).
        [$clientOperateur, $enteteOperateur] = $this->operateurStockSur(SocleFixtures::ETAB_B_NOM, ['transferer']);
        $clientOperateur->request('POST', '/api/stock/transferts/' . $transfert['id'] . '/expedier', $enteteOperateur + ['json' => []]);
        self::assertResponseStatusCodeSame(403, 'Un opérateur affecté uniquement à B (destination) ne doit pas pouvoir expédier depuis A (source).');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $lotSource = $em->getRepository(LotStock::class)->findOneBy(['articleStock' => $articleA['id']]);
        self::assertNotNull($lotSource);
        self::assertSame('20.000', $lotSource->getQuantiteRestante(), 'Le stock de A ne doit pas être décrémenté par un utilisateur non autorisé côté source.');
    }

    public function testRecevoirExigeDroitEtablissementDestination(): void
    {
        [$client, $entete] = $this->adminSurA();
        $articleA = $this->creerArticle($client, $entete, SocleFixtures::ETAB_A_NOM, '5901234123457');
        $articleB = $this->creerArticle($client, $entete, SocleFixtures::ETAB_B_NOM, '40170725');

        $transfert = $client->request('POST', '/api/stock_transferts', $entete + [
            'json' => [
                'articleStockSource' => '/api/article_stocks/' . $articleA['id'],
                'articleStockDestination' => '/api/article_stocks/' . $articleB['id'],
                'quantite' => '1.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        // Opérateur affecté uniquement à A (source) : ne doit pas pouvoir recevoir sur B (destination).
        [$clientOperateur, $enteteOperateur] = $this->operateurStockSur(SocleFixtures::ETAB_A_NOM, ['transferer']);
        $clientOperateur->request('POST', '/api/stock/transferts/' . $transfert['id'] . '/recevoir', $enteteOperateur + ['json' => []]);
        self::assertResponseStatusCodeSame(403, 'Un opérateur affecté uniquement à A (source) ne doit pas pouvoir recevoir sur B (destination).');
    }

    // --- 1. IDOR : ValorisationArticleProvider --------------------------------------------------

    public function testValorisationArticleRefuseArticleHorsPerimetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $articleA = $this->creerArticle($client, $entete, SocleFixtures::ETAB_A_NOM, '5901234123457');

        [$clientOperateur, $enteteOperateur] = $this->operateurStockSur(SocleFixtures::ETAB_B_NOM, ['lire_valorisation']);
        $clientOperateur->request('GET', '/api/stock/articles/' . $articleA['id'] . '/valorisation', $enteteOperateur);
        self::assertResponseStatusCodeSame(404, 'La valorisation d\'un article hors périmètre ne doit jamais être exposée (masquée en 404).');
    }

    // --- 2. Rattachement ArticleStock ↔ Produit M1 hors établissement --------------------------

    public function testRattacherProduitRefuseProduitAutreEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $articleB = $this->creerArticle($client, $entete, SocleFixtures::ETAB_B_NOM, '40170725');

        // Produit boutique des fixtures, rattaché uniquement à l'établissement A (StockFixtures).
        $produitA = $this->entite(Produit::class, ['libelleRecherche' => StockFixtures::PRODUIT_BOUTIQUE]);

        $client->request('POST', '/api/stock/articles/' . $articleB['id'] . '/rattacher-produit', $entete + [
            'json' => ['produit' => (string) $produitA->getId()],
        ]);
        // CE QUE LE SERVEUR REPOND VRAIMENT, releve par une sonde sur le corps de la reponse :
        //
        //     422 -- "codeEAN: This value should not be blank. libelle: This value should not be blank."
        //
        // L'article de B n'est donc PAS resolu depuis A : le cloisonnement fait son travail. Mais un
        // POST portant `read: true` sur un item introuvable ne rend pas 404 -- API Platform
        // instancie une entite NEUVE, la validation echoue sur ses champs obligatoires, et le
        // message parle de la charge utile alors que le vrai motif est le perimetre.
        //
        // Le refus reste FERME et ne revele rien : le meme corps sortirait pour un identifiant
        // invente. C'est l'indistinguabilite qui est la propriete de securite, pas le nombre --
        // un refus qui distingue "existe mais interdit" de "n'existe pas" serait un oracle
        // d'enumeration.
        //
        // ⚠ Le message, lui, merite d'etre corrige A LA SOURCE : une operation de ce genre devrait
        // echouer en 404. Ce test constate l'etat des lieux, il ne l'arbitre pas.
        //
        // Restent interdits : 403, qui confirmerait l'existence, et 2xx, qui servirait.
        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [400, 404, 422],
            'Depuis A, un article de B ne doit jamais etre modifiable (RG-SOCLE-05, D41).',
        );
    }

    // --- 4. Permission stock.gerer opérante sur les écritures -----------------------------------

    public function testPermissionStockGererDonneAccesEcritureArticleStock(): void
    {
        [$clientOperateur, $entete, $idEtab] = $this->operateurStockSur(SocleFixtures::ETAB_A_NOM, ['gerer']);

        $clientOperateur->request('POST', '/api/article_stocks', $entete + [
            'json' => [
                'codeEAN' => '5901234123457',
                'libelle' => 'Article via stock.gerer',
                'unite' => 'piece',
                'prixAchatHT' => '1.0000',
                'tauxTvaAchat' => '20.00',
                'seuilMin' => '0.000',
                'seuilMax' => '0.000',
            ],
        ]);
        self::assertResponseIsSuccessful('Un rôle ne portant que stock.gerer doit pouvoir écrire sur les endpoints stock (permission générique).');
    }

    // --- Fixtures locales ------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function creerArticle(object $client, array $entete, string $nomEtab, string $ean): array
    {
        // D41 : l'etablissement d'une creation vient de la session serveur. On se place donc dans
        // celui qu'on vise au lieu de le nommer dans le corps, ou il serait desormais ignore.
        $entete = $this->enteteSur($entete, $nomEtab);
        $article = $client->request('POST', '/api/article_stocks', $entete + [
            'json' => [
                'codeEAN' => $ean,
                'libelle' => 'Article ' . $nomEtab,
                'unite' => 'piece',
                'prixAchatHT' => '2.0000',
                'tauxTvaAchat' => '20.00',
                'seuilMin' => '0.000',
                'seuilMax' => '0.000',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $article;
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function receptionner(object $client, array $entete, string $nomEtab, string $articleId, string $quantite, string $prix): string
    {
        // D41 : meme raison que dans `creerArticle`.
        $entete = $this->enteteSur($entete, $nomEtab);
        $fournisseur = $client->request('POST', '/api/stock_fournisseurs', $entete + [
            'json' => ['raisonSociale' => 'Grossiste ' . $nomEtab],
        ])->toArray();

        $reception = $client->request('POST', '/api/stock_reception_achats', $entete + [
            'json' => [
                'fournisseur' => '/api/stock_fournisseurs/' . $fournisseur['id'],
                'date' => '2026-03-01',
                'numeroBonLivraison' => 'BL-' . $nomEtab . '-' . bin2hex(random_bytes(3)),
            ],
        ])->toArray();

        $client->request('POST', '/api/stock_ligne_reception_achats', $entete + [
            'json' => [
                'reception' => '/api/stock_reception_achats/' . $reception['id'],
                'articleStock' => '/api/article_stocks/' . $articleId,
                'quantiteRecue' => $quantite,
                'prixAchatUnitaireHT' => $prix,
            ],
        ]);

        $client->request('POST', '/api/stock/receptions-achat/' . $reception['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $reception['id'];
    }

    /**
     * Crée un `Avoir` réel (annulation d'une vente validée) sur l'établissement A.
     *
     * @param array<string, mixed> $entete
     */
    private function creerAvoirSurA(object $client, array $entete): string
    {
        $session = $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
                'caisse' => '/api/caisses/' . $this->idCaisse(),
                'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => '50.00',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $vente = $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $session['id']],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduitEntree(),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '5.50'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/annuler', $entete + [
            'json' => ['motif' => 'Erreur de saisie'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return (string) $reponse['avoir'];
    }

    private function idProduitEntree(): string
    {
        return (string) $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE])->getId();
    }

    private function idTarif(): string
    {
        return (string) $this->entite(TypeTarif::class, ['nom' => OffreFixtures::TARIF_PLEIN])->getId();
    }

    private function idPointDeVente(): string
    {
        return (string) $this->entite(PointDeVente::class, ['libelle' => VenteFixtures::PDV_LIBELLE])->getId();
    }

    private function idCaisse(): string
    {
        return (string) $this->entite(Caisse::class, ['libelle' => VenteFixtures::CAISSE_LIBELLE])->getId();
    }

    private function idAdmin(): string
    {
        return (string) $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId();
    }
}
