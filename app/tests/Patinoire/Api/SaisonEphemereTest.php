<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\Entity\SaisonEphemere;
use App\Securite\Entity\Utilisateur;
use App\Tests\Patinoire\PatinoireApiTestCase;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Saisonnalité — patinoires éphémères (US-PATIN-10, RG-PAT-04, CA-10). Le listener
 * `VerificateurFenetreSaisonEphemereListener` (`prePersist` de `App\Vente\Entity\LigneVente`) ferme la
 * vente automatiquement hors fenêtre, sans modifier `App\Vente`/`App\Offre`.
 */
final class SaisonEphemereTest extends PatinoireApiTestCase
{
    public function testVenteFermeeAutomatiquementHorsFenetre(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();

        $produit = Uuid::v4();
        $this->configurerSaisonEphemere($client, $entete, $idA, $produit, '-30 days', '-1 day');

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/RG-PAT-04/');
        $this->persisterLigneVente($idA, $produit);
    }

    public function testReouvertureAutomatiqueDansLaFenetre(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();

        $produit = Uuid::v4();
        $this->configurerSaisonEphemere($client, $entete, $idA, $produit, '-1 day', '+30 days');

        $ligne = $this->persisterLigneVente($idA, $produit);
        self::assertNotNull($ligne->getId(), 'CA-10 : vente autorisée dans la fenêtre, aucune exception levée.');
    }

    public function testProduitHorsCatalogueAssocieNestPasImpacte(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();

        $produitSaison = Uuid::v4();
        $this->configurerSaisonEphemere($client, $entete, $idA, $produitSaison, '-30 days', '-1 day');

        // Produit non associé à la saison éphémère fermée : aucune restriction (RG-PAT-04 ne s'applique
        // qu'au `catalogueAssocie`).
        $ligne = $this->persisterLigneVente($idA, Uuid::v4());
        self::assertNotNull($ligne->getId());
    }

    /** @param array<string, mixed> $entete */
    private function configurerSaisonEphemere(object $client, array $entete, string $idA, Uuid $produit, string $fenetreDebutModifier, string $fenetreFinModifier): void
    {
        $client->request('POST', '/api/patinoire_saison_ephemeres', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $idA,
                'libelle' => 'Saison éphémère test',
                'dateOuverture' => (new \DateTimeImmutable('-60 days'))->format('Y-m-d'),
                'dateFermeture' => (new \DateTimeImmutable('+60 days'))->format('Y-m-d'),
                'fenetreVenteDebut' => (new \DateTimeImmutable($fenetreDebutModifier))->format('Y-m-d'),
                'fenetreVenteFin' => (new \DateTimeImmutable($fenetreFinModifier))->format('Y-m-d'),
                'catalogueAssocie' => [(string) $produit],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $saison = $client->getResponse()->toArray();
        self::assertSame([(string) $produit], $saison['catalogueAssocie']);
    }

    private function persisterLigneVente(string $idEtablissement, Uuid $produit): LigneVente
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etablissement = $em->getRepository(Etablissement::class)->find($idEtablissement);
        self::assertNotNull($etablissement);
        $admin = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertNotNull($admin);

        $pdv = (new PointDeVente())->setLibelle('PDV test saison éphémère')->setEtablissement($etablissement);
        $em->persist($pdv);
        $caisse = (new Caisse())->setLibelle('Caisse test')->setPointDeVente($pdv)->setEtat(EtatCaisse::Ouverte);
        $em->persist($caisse);
        $session = (new SessionCaisse())->setNumero('S-TEST-' . uniqid())->setPointDeVente($pdv)->setCaisse($caisse)
            ->setRegisseur($admin)->setOperateur($admin)->setFondDeCaisse('0.00')->setEtablissement($etablissement);
        $em->persist($session);
        $vente = (new Vente())->setNumero('V-TEST-' . uniqid())->setSession($session)->setEtablissement($etablissement);
        $em->persist($vente);

        $ligne = new LigneVente();
        $ligne->setVente($vente)->setProduit($produit)->setTypeTarif(Uuid::v4())->setPrixUnitaire('10.00');
        $em->persist($ligne);
        $em->flush();

        return $ligne;
    }
}
