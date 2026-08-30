<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\ProductAccessZone;
use App\Acces\Entity\Support;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Acces\AccesApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;
use App\Vente\Entity\BilletSupport;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Un billet vendu ouvre la porte — la chaîne entière, de l'encaissement au tourniquet.
 *
 * ── CE QUI MANQUAIT, ET CE QUI NE MANQUAIT PAS ─────────────────────────────────────────────────
 *
 * Maxime, 30/08 : « je vois qu'un QR code n'ouvre toujours pas le contrôle d'accès ». Mesuré :
 * 5 billets vendus, 5 supports d'accès, **aucun croisement**. Le tourniquet répondait « Support
 * inconnu » à un code dont il savait pourtant vérifier la signature.
 *
 * Rien n'était à inventer. `ValiderVenteService:149` appelait déjà `appairer()` à chaque vente ; le
 * port `App\Vente\Port\AppairageAccesInterface` était simplement câblé sur un **stub** qui basculait
 * un statut sans jamais parler au module Accès. La projection existait, l'appairage existait. Il
 * manquait l'implémentation du port : `App\Acces\Adapter\SaleAccessPairingAdapter`.
 *
 * ── POURQUOI CE TEST DÉCLARE UNE ZONE, ET POURQUOI C'EST LE CŒUR DU SUJET ──────────────────────
 *
 * ⚠ `DroitAcces::ouvre()` rend `true` quand aucun espace n'est autorisé : **un droit sans espace
 * ouvre tout**. Mesuré le 30/08 : 0 produit sur 17 déclarait une zone, pour 8 espaces existants.
 * Brancher la projection sans condition aurait donc fait de chaque billet vendu un passe-partout des
 * huit espaces — une régression de sécurité à l'échelle de toutes les ventes, et silencieuse.
 *
 * L'adaptateur ne projette donc que si le produit déclare une zone. Ce test la déclare : c'est le
 * geste que l'exploitant devra faire, et sans lui le billet n'ouvre rien — ce que prouve le second
 * test.
 */
final class BilletVenduOuvreLaPorteTest extends AccesApiTestCase
{
    /** La chaîne complète : vendre une entrée, puis présenter son code au tourniquet. */
    public function testUnBilletVenduPourUnProduitAZoneOuvreLaPorte(): void
    {
        $this->declarerZoneSurLEntree();

        $identifiant = $this->vendreUneEntreeEtRecupererSonCode();

        // ── TÉMOIN INTERMÉDIAIRE : le module Accès connaît désormais ce support ─────────────────
        //
        // Sans lui, un passage refusé plus bas ne dirait pas SI le pont a fonctionné : « support
        // inconnu » et « droit insuffisant » se ressemblent depuis la réponse du tourniquet.
        $this->em()->clear();
        $support = $this->em()->getRepository(Support::class)->findOneBy(['identifiant' => $identifiant]);
        self::assertNotNull($support, 'La vente doit avoir créé un support côté Accès.');

        // ── ET LE TOURNIQUET OUVRE ─────────────────────────────────────────────────────────────
        $reponse = static::createClient()->request('POST', '/api/terminal/passages', $this->terminalEntete() + [
            'json' => [
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => $identifiant,
                'cleIdempotence' => (string) Uuid::v4(),
            ],
        ]);

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame(
            'valide',
            $reponse->toArray()['resultat'],
            'Un billet vendu pour un produit qui déclare une zone doit ouvrir cette zone.',
        );
    }

    /**
     * Sans zone déclarée, le billet n'ouvre rien — et surtout, il n'ouvre pas TOUT.
     *
     * ⚠ C'est le témoin qui distingue « le pont fonctionne » de « le pont ouvre les huit espaces ».
     * Sans lui, le premier test serait également vert si l'adaptateur projetait sans condition — et
     * ce serait exactement le défaut à ne pas introduire.
     */
    public function testSansZoneDeclareeLeBilletNOuvreRienEtLaVentePasse(): void
    {
        // Aucune zone déclarée : on ne touche pas au produit.
        $identifiant = $this->vendreUneEntreeEtRecupererSonCode();

        $this->em()->clear();
        self::assertNull(
            $this->em()->getRepository(Support::class)->findOneBy(['identifiant' => $identifiant]),
            "Sans zone déclarée, aucun droit ne doit être projeté — un droit sans espace ouvrirait TOUT.",
        );

        $reponse = static::createClient()->request('POST', '/api/terminal/passages', $this->terminalEntete() + [
            'json' => [
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => $identifiant,
                'cleIdempotence' => (string) Uuid::v4(),
            ],
        ]);

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame('refuse', $reponse->toArray()['resultat'], 'Le billet ne doit rien ouvrir.');
    }

    // ── Ce que fait l'exploitant : dire quelle porte ce produit ouvre ───────────────────────────

    private function declarerZoneSurLEntree(): void
    {
        /** @var Produit $produit */
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        /** @var EspaceAcces $espace */
        $espace = $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        /** @var Etablissement $etablissement */
        $etablissement = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        // Les deux valeurs que le client fournit sont exigées au constructeur : l'entité rend
        // inatteignable l'état « colonne NOT NULL laissée nulle », au lieu de le rendre détectable.
        $zone = (new ProductAccessZone($produit->getId(), $espace))->setEstablishment($etablissement);

        $this->em()->persist($zone);
        $this->em()->flush();
        $this->em()->clear();
    }

    // ── Une vente réelle, par l'API, comme en caisse ────────────────────────────────────────────

    private function vendreUneEntreeEtRecupererSonCode(): string
    {
        $client = static::createClient();
        $client->disableReboot();

        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $idA],
        ];

        $session = $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/'.$this->idParLibelle(PointDeVente::class, VenteFixtures::PDV_LIBELLE),
                'caisse' => '/api/caisses/'.$this->idParLibelle(Caisse::class, VenteFixtures::CAISSE_LIBELLE),
                'regisseur' => '/api/utilisateurs/'.$this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => '50.00',
            ],
        ])->toArray();

        $vente = $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/'.$session['id']],
        ])->toArray();

        $client->request('POST', '/api/ventes/'.$vente['id'].'/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/'.$this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE])->getId(),
                'typeTarif' => '/api/type_tarifs/'.$this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful("ajout de la ligne d'entrée");

        $client->request('POST', '/api/ventes/'.$vente['id'].'/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        $client->request('POST', '/api/ventes/'.$vente['id'].'/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful('validation de la vente');

        $this->em()->clear();

        /** @var list<BilletSupport> $supports */
        $supports = $this->em()->getRepository(BilletSupport::class)->findBy(['vente' => $vente['id']]);
        self::assertCount(1, $supports, 'La vente doit avoir émis exactement un support.');

        $identifiant = $supports[0]->getIdentifiantSupport();
        self::assertNotNull($identifiant, "Le support vendu doit porter un identifiant signé.");

        return $identifiant;
    }

    /** @param class-string $classe */
    private function idParLibelle(string $classe, string $libelle): string
    {
        return (string) $this->entite($classe, ['libelle' => $libelle])->getId();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
