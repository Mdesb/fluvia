<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Organisation\Entity\Etablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-FACT-02, RG-FACT-03.2 (CA-3, CA-4) : une facture directe (vente à terme) EST le fait générateur
 * comptable — une seule écriture équilibrée, inaltérable dès l'émission.
 */
final class FactureDirecteApiTest extends FacturationApiTestCase
{
    public function testCa3EmissionGenereEcritureEquilibreeEtCreance(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        self::assertSame('brouillon', $brouillon['statut']);
        self::assertNull($brouillon['numero']);

        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();

        self::assertSame('en_attente_paiement', $emise['statut']);
        self::assertNotNull($emise['numero']);
        self::assertStringStartsWith('FA-', $emise['numero']);
        self::assertNotNull($emise['ecritureGeneree']);
        self::assertSame('120.00', $emise['totalTTC']);

        $ecritureIri = $emise['ecritureGeneree'];
        $ecritureId = basename((string) $ecritureIri);
        $ecriture = $client->request('GET', '/api/ecriture_comptables/' . $ecritureId, $entete)->toArray();

        $debit = 0;
        $credit = 0;
        foreach ($ecriture['lignes'] as $ligne) {
            $debit += (int) $ligne['debitCentimes'];
            $credit += (int) $ligne['creditCentimes'];
        }
        self::assertSame($debit, $credit, 'Écriture équilibrée (CA-3).');
        self::assertSame(12000, $debit, 'Créance = TTC de la facture.');
    }

    public function testCa4ReEmissionRejetee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(50.0),
        ])->toArray();
        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nbEcrituresAvant = (int) $em->getRepository(\App\Compta\Entity\EcritureComptable::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $reponse = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);
        self::assertSame(409, $reponse->getStatusCode(), 'CA-4 : ré-émission rejetée.');

        $nbEcrituresApres = (int) $em->getRepository(\App\Compta\Entity\EcritureComptable::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($nbEcrituresAvant, $nbEcrituresApres, 'Pas de deuxième écriture.');
    }

    public function testCompteProduitIndeterminableRejeteExplicitement(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Retire le repli de compte produit par défaut pour forcer l'indétermination (§0.2 du plan).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $parametre = $em->getRepository(ParametreFacturationEtablissement::class)->findOneBy(['profilExploitant' => $this->profilExploitant()->getId()]);
        self::assertNotNull($parametre);
        $parametre->setCompteProduitDefaut(null);
        $em->flush();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(75.0),
        ])->toArray();

        $reponse = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);
        self::assertSame(422, $reponse->getStatusCode(), 'Compte produit indéterminable : rejet explicite, pas de crash.');
    }

    /**
     * Correctif revue de cohérence (minor) : `FactureDirecteBuilder::resoudreTauxTva` doit rejeter un
     * taux de TVA appartenant à un autre exploitant (RG-SOCLE-05).
     */
    public function testTauxTvaAutreExploitantRejeteExplicitement(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);

        $profilB = new ProfilExploitant();
        $profilB->setType(TypeExploitant::RegieDirecte);
        $profilB->setReferentielComptable(ReferentielComptable::M57);
        $profilB->setSiren('888888888');
        $profilB->setEtablissementPrincipal($etabB);
        $em->persist($profilB);

        $tauxB = new TauxTva();
        $tauxB->setProfilExploitant($profilB);
        $tauxB->setTaux('20.00');
        $tauxB->setLibelle('Taux normal 20 % (profil B)');
        $tauxB->setActif(true);
        $em->persist($tauxB);
        $em->flush();

        $corps = $this->corpsFactureDirecte(60.0);
        $corps['lignes'][0]['tauxTva'] = '/api/taux_tvas/' . $tauxB->getId();

        $reponse = $client->request('POST', '/api/factures', $entete + ['json' => $corps]);
        self::assertSame(422, $reponse->getStatusCode(), 'Taux de TVA d\'un autre exploitant : rejet explicite.');
    }
}
