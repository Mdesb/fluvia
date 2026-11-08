<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\ProductAccessZone;
use App\Acces\Entity\Support;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Import\ImportApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;

/**
 * #348 — reprise des abonnements en cours et des soldes de cartes.
 *
 * Le test qui compte est le premier : une carte reprise s'ouvre au TOURNIQUET, et son crédit y est
 * décompté. Une reprise « appliquée » dont la carte serait refusée à l'entrée est le défaut qu'on
 * découvre devant la personne, six semaines plus tard.
 *
 * Cloisonnement : deux établissements (A et B). Un import fait sur B ne peut pas désigner un produit
 * de A, et la carte témoin de A (fixtures du contrôle d'accès) traverse toutes les reprises intacte.
 */
final class AccessRightImportTest extends ImportApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();
        $em = $this->em();
        foreach ([OffreFixtures::class, VenteFixtures::class, AccesFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }
        self::ensureKernelShutdown();
    }

    public function testImportedCardCreditOpensTheGateAndIsDecremented(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->declarerZone(OffreFixtures::PRODUIT_CARTE);
        $code = $this->codeProduit(OffreFixtures::PRODUIT_CARTE);
        $temoin = $this->creditTemoin();

        $csv = "externalRef;supportIdentifiant;produit;creditRestant;dateFin\n"
            . "OLD-C1;REPRISE-C1;$code;4;" . (new \DateTimeImmutable('+6 months'))->format('Y-m-d') . "\n"
            . "OLD-C2;REPRISE-C2;$code;2;\n";
        $lot = $this->deposer($client, $entete, $csv, 'card_credits', '6');
        self::assertSame('validated', $lot['status'], json_encode($lot['errors'] ?? null));

        $client->request('POST', '/api/imports/' . $lot['id'] . '/appliquer', $entete);
        self::assertResponseIsSuccessful();

        // AU TOURNIQUET : la carte reprise ouvre, et le crédit descend de un.
        $passage = $client->request('POST', '/api/acces/passages', $entete + ['json' => [
            'equipement' => '/api/equipements/' . $this->entite(Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE])->getId(),
            'identifiantSupport' => 'REPRISE-C1',
        ]])->toArray();
        self::assertSame('valide', $passage['resultat'], 'la carte reprise doit ouvrir le tourniquet : ' . json_encode($passage));

        $this->em()->clear();
        $droit = $this->entite(DroitAcces::class, ['externalRef' => 'OLD-C1']);
        self::assertSame(3, $droit->getCreditRestant());
        self::assertSame($temoin, $this->creditTemoin(), 'TÉMOIN : la carte de démonstration de A ne doit pas bouger');

        // Un crédit entamé ne s'annule plus.
        $client->request('POST', '/api/imports/' . $lot['id'] . '/annuler', $entete);
        self::assertResponseStatusCodeSame(409);
    }

    public function testExpectedTotalMismatchRejectsTheWholeBatch(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->declarerZone(OffreFixtures::PRODUIT_CARTE);
        $code = $this->codeProduit(OffreFixtures::PRODUIT_CARTE);

        $csv = "externalRef;supportIdentifiant;produit;creditRestant\nOLD-C1;REPRISE-C1;$code;4\nOLD-C2;REPRISE-C2;$code;2\n";
        $lot = $this->deposer($client, $entete, $csv, 'card_credits', '7');
        self::assertSame('rejected', $lot['status']);
        self::assertStringContainsString('écart de 1', (string) $lot['errors'][0]);

        $sansTotal = $this->deposer($client, $entete, $csv, 'card_credits', null, 'sans-total.csv');
        self::assertSame('rejected', $sansTotal['status'], 'sans total annoncé, un lot de crédits ne passe pas');
        self::assertSame(0, $this->em()->getRepository(Support::class)->count(['identifiant' => 'REPRISE-C1']));
    }

    public function testImportedSubscriptionOpensTheGateUntilItsEndDate(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->declarerZone(OffreFixtures::PRODUIT_GOLD);
        $code = $this->codeProduit(OffreFixtures::PRODUIT_GOLD);
        $fin = (new \DateTimeImmutable('+3 months'))->format('Y-m-d');

        $lot = $this->deposer($client, $entete, "externalRef;supportIdentifiant;produit;dateFin
OLD-S1;REPRISE-S1;$code;$fin
", 'subscribers');
        self::assertSame('validated', $lot['status'], json_encode($lot['errors'] ?? null));
        $client->request('POST', '/api/imports/' . $lot['id'] . '/appliquer', $entete);
        self::assertResponseIsSuccessful();

        $passage = $client->request('POST', '/api/acces/passages', $entete + ['json' => [
            'equipement' => '/api/equipements/' . $this->entite(Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE])->getId(),
            'identifiantSupport' => 'REPRISE-S1',
        ]])->toArray();
        self::assertSame('valide', $passage['resultat'], "l'abonnement repris doit ouvrir le tourniquet : " . json_encode($passage));

        $this->em()->clear();
        $droit = $this->entite(DroitAcces::class, ['externalRef' => 'OLD-S1']);
        self::assertSame($fin . ' 23:59:59', $droit->getFenetreFin()?->format('Y-m-d H:i:s'));
    }

    public function testUnusedSubscriptionBatchRevertsCleanly(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->declarerZone(OffreFixtures::PRODUIT_GOLD);
        $code = $this->codeProduit(OffreFixtures::PRODUIT_GOLD);
        $fin = (new \DateTimeImmutable('+3 months'))->format('d/m/Y');

        $csv = "externalRef;supportIdentifiant;produit;dateFin\nOLD-A1;REPRISE-A1;$code;$fin\nOLD-A2;REPRISE-A2;$code;$fin\n";
        $lot = $this->deposer($client, $entete, $csv, 'subscribers');
        self::assertSame('validated', $lot['status'], json_encode($lot['errors'] ?? null));
        $client->request('POST', '/api/imports/' . $lot['id'] . '/appliquer', $entete);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $droit = $this->entite(DroitAcces::class, ['externalRef' => 'OLD-A1']);
        self::assertSame('abonnement', $droit->getSourceType()->value);
        self::assertNull($droit->getCreditRestant());
        self::assertNotEmpty($droit->getAuthorisedSpaces()->toArray(), 'D87 : sans zone, le droit repris n\'ouvrirait aucune porte');

        // Annulation d'un lot qui n'a pas servi : tout ce qu'il a créé disparaît, et rien d'autre.
        $client->request('POST', '/api/imports/' . $lot['id'] . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame(0, $this->em()->getRepository(Support::class)->count(['identifiant' => 'REPRISE-A1']));
        self::assertSame(0, $this->em()->getRepository(DroitAcces::class)->count(['externalRef' => 'OLD-A1']));
        self::assertSame(1, $this->em()->getRepository(Support::class)->count(['identifiant' => AccesFixtures::SUPPORT_IDENTIFIANT]), 'TÉMOIN : l\'annulation ne touche que ce que le lot a créé');
        self::assertGreaterThan(0, $this->em()->getRepository(Appairage::class)->count([]), 'TÉMOIN : l\'appairage de démonstration subsiste');
    }

    public function testEachRowIsCheckedBeforeAnythingIsWritten(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->declarerZone(OffreFixtures::PRODUIT_GOLD);
        $gold = $this->codeProduit(OffreFixtures::PRODUIT_GOLD);
        $carte = $this->codeProduit(OffreFixtures::PRODUIT_CARTE); // pas de zone déclarée
        $hier = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $plusTard = (new \DateTimeImmutable('+1 month'))->format('Y-m-d');

        $csv = "externalRef;supportIdentifiant;produit;dateFin\n"
            . "R1;N1;$gold;$hier\n"                                         // 2 : échu
            . "R2;N2;INCONNU;$plusTard\n"                                    // 3 : produit inconnu
            . "R3;" . AccesFixtures::SUPPORT_IDENTIFIANT . ";$gold;$plusTard\n" // 4 : carte déjà connue
            . "R4;N4;$carte;$plusTard\n"                                     // 5 : carte à entrées
            . "R4;N5;$gold;$plusTard\n"                                      // 6 : référence dupliquée
            . "R6;N6;$gold;$plusTard\n";                                     // 7 : bonne
        $lot = $this->deposer($client, $entete, $csv, 'subscribers');

        self::assertSame('rejected', $lot['status']);
        $erreurs = $lot['errors'];
        self::assertStringContainsString('échu', $erreurs[2]);
        self::assertStringContainsString('inconnu', $erreurs[3]);
        self::assertStringContainsString('existe déjà', $erreurs[4]);
        self::assertArrayHasKey(5, $erreurs);
        self::assertStringContainsString('dupliqué', $erreurs[6]);
        self::assertArrayNotHasKey(7, $erreurs, 'la bonne ligne n\'est pas en erreur');
        self::assertSame(0, $this->em()->getRepository(Support::class)->count(['identifiant' => 'N6']), 'un lot rejeté n\'écrit rien');
    }

    /** B ne peut pas reprendre sur un produit de A ; la carte témoin de A reste intacte. */
    public function testOtherEstablishmentCannotUseAProductOfA(): void
    {
        $this->declarerZone(OffreFixtures::PRODUIT_GOLD);
        $gold = $this->codeProduit(OffreFixtures::PRODUIT_GOLD);
        $temoin = $this->creditTemoin();
        [$client, $entete] = $this->adminSurB();

        $csv = "externalRef;supportIdentifiant;produit;dateFin\nB1;REPRISE-B1;$gold;" . (new \DateTimeImmutable('+1 month'))->format('Y-m-d') . "\n";
        $lot = $this->deposer($client, $entete, $csv, 'subscribers');
        self::assertSame('rejected', $lot['status']);
        self::assertStringContainsString('inconnu dans cet établissement', $lot['errors'][2]);
        self::assertSame(0, $this->em()->getRepository(Support::class)->count(['identifiant' => 'REPRISE-B1']));
        self::assertSame($temoin, $this->creditTemoin(), 'TÉMOIN : rien ne bouge chez A');
    }

    /** @param array<string, mixed> $entete @return array<string, mixed> */
    private function deposer(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $csv, string $type, ?string $total = null, string $nom = 'reprise.csv'): array
    {
        $corps = ['type' => $type, 'fileName' => $nom, 'mimeType' => 'text/csv', 'content' => base64_encode($csv)];
        if ($total !== null) {
            $corps['expectedTotal'] = $total;
        }

        return $client->request('POST', '/api/imports', $entete + ['json' => $corps])->toArray(false);
    }

    private function declarerZone(string $libelleProduit): void
    {
        $produit = $this->entite(Produit::class, ['libelleRecherche' => $libelleProduit]);
        $espace = $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $this->em()->persist((new ProductAccessZone($produit->getId(), $espace))->setEstablishment($etab));
        $this->em()->flush();
        $this->em()->clear();
    }

    private function codeProduit(string $libelleProduit): string
    {
        return $this->entite(Produit::class, ['libelleRecherche' => $libelleProduit])->getCode();
    }

    /** Crédit de la carte de démonstration de A, qui ne doit jamais bouger. */
    private function creditTemoin(): ?int
    {
        $this->em()->clear();
        $support = $this->entite(Support::class, ['identifiant' => AccesFixtures::SUPPORT_IDENTIFIANT]);
        $appairage = $this->entite(Appairage::class, ['support' => $support]);

        return $appairage->getDroit()?->getCreditRestant();
    }
}
