<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Sepa\Entity\MandatSepa;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\StatutAccesFitness;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Souscription d'un abonnement fitness (US-SPORT-01, CA-1, RG-M1-03) : abonnement `actif`, mandat SEPA
 * signé (IBAN jamais en clair en base), échéancier mensuel généré jusqu'à la fin d'engagement, droit
 * d'accès actif dès le rattachement synchrone (§0 point 5 du plan).
 */
final class SouscriptionTest extends SportApiTestCase
{
    public function testCa1SouscriptionCreeAbonnementActifMandatEtEcheancier(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produitGold = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_GOLD]);
        self::assertNotNull($produitGold);
        $payeur = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        $enfant = $em->getRepository(Client::class)->findOneBy(['prenom' => CrmFixtures::ENFANT_PRENOM]);
        $adherent = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $enfant]);
        self::assertNotNull($payeur);
        self::assertNotNull($adherent);

        $client->request('POST', '/api/sport/abonnements/souscrire', $entete + [
            'json' => [
                'adherent' => '/api/beneficiaires/' . $adherent->getId(),
                'payeur' => '/api/clients/' . $payeur->getId(),
                'formule' => '/api/formules/' . $produitGold->getFormule()->getId(),
                'periodicite' => 'mensuel',
                'dureeEngagementMois' => 12,
                'montantCentimes' => 3990,
                'iban' => 'FR7630006000011234567890189',
                'titulaireMandat' => 'Jean Dupont',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $abonnement = $client->getResponse()->toArray();
        self::assertSame('actif', $abonnement['statut']);
        self::assertArrayHasKey('mandatSepa', $abonnement);

        $abonnementId = $abonnement['id'];

        // Mandat signé, IBAN jamais exposé (embarqué dans la réponse abonnement, groupe `abonnement:read`).
        self::assertSame('actif', $abonnement['mandatSepa']['statut']);
        self::assertSame('0189', $abonnement['mandatSepa']['iban4Derniers']);
        self::assertArrayNotHasKey('ibanToken', $abonnement['mandatSepa']);

        $mandatIri = $abonnement['mandatSepa']['@id'];
        $client->request('GET', $mandatIri, $entete);
        self::assertResponseIsSuccessful();
        $mandat = $client->getResponse()->toArray();
        self::assertSame('actif', $mandat['statut']);
        self::assertSame('0189', $mandat['iban4Derniers']);
        self::assertArrayNotHasKey('ibanToken', $mandat);
        self::assertArrayNotHasKey('iban', $mandat);

        // Échéancier mensuel généré jusqu'à la fin d'engagement (12 échéances).
        $client->request('GET', '/api/echeance_sepas', $entete + ['query' => ['abonnement' => $abonnementId, 'itemsPerPage' => 50]]);
        self::assertResponseIsSuccessful();

        /** @var list<EcheanceSepa> $echeances */
        $echeances = $em->getRepository(EcheanceSepa::class)->findBy(['abonnement' => $abonnementId]);
        self::assertCount(12, $echeances);
        foreach ($echeances as $echeance) {
            self::assertSame('a_venir', $echeance->getStatut()->value);
            self::assertSame(3990, $echeance->getMontantCentimes());
        }

        // StatutAccesFitness ouvert, actif par défaut (droit d'accès rattaché ensuite en agence).
        $statutAcces = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnementId]);
        self::assertNotNull($statutAcces);
        self::assertTrue($statutAcces->isActif());

        // IBAN jamais persisté en clair.
        $mandatEntite = $em->getRepository(MandatSepa::class)->find($mandat['id']);
        self::assertNotNull($mandatEntite);
        self::assertStringNotContainsString('FR7630006000011234567890189', $mandatEntite->getIbanToken());
    }

    public function testDroitAccesActifDesLeRattachementSynchrone(): void
    {
        [$client, $entete] = $this->adminSurA();

        $abonnementId = $this->idAbonnementDemo();
        $droitId = $this->idDroitAccesDemo();

        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/rattacher-droit-acces', $entete + [
            'json' => ['droitAcces' => '/api/droit_acces/' . $droitId],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $statutAcces = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnementId]);
        self::assertNotNull($statutAcces);
        self::assertNotNull($statutAcces->getDroitAcces());
        self::assertSame('valide', $statutAcces->getDroitAcces()->getStatutProjection()->value);
    }
}
