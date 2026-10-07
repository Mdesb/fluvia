<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Command;

use App\Reporting\DataFixtures\L11Fixtures;
use App\Reporting\Entity\Export;
use App\Reporting\Entity\RapportPlanifie;
use App\Reporting\Enum\StatutExport;
use App\Reporting\Notification\TransportCourrielInterface;
use App\Tests\Reporting\ReportingApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `reporting:executer-rapports` (CA-7, CA-8, RG-M7-06/07) : un rapport actif échu génère et envoie
 * automatiquement, un `Export` INDIVIDUALISÉ par destinataire ; un rapport suspendu n'est jamais
 * sélectionné.
 */
final class ExecuterRapportsCommandTest extends ReportingApiTestCase
{
    public function testRapportActifGenereEtEnvoieUnExportParDestinataire(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);
        $idA2 = $this->idEtablissement(L11Fixtures::SITE_A2_NOM);
        $tdb = $this->creerTableauDeBord('region', '/api/regions/' . $this->idRegion(L11Fixtures::REGION_A_NOM));

        $rapport = $client->request('POST', '/api/rapport_planifies', $entete + [
            'json' => [
                'nom' => 'Rapport automatique',
                'tableauDeBord' => $tdb,
                'format' => 'csv',
                'periodicite' => 'quotidienne',
                'heureEnvoi' => '00:00',
                'destinataires' => [
                    ['email' => 'a1@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA1],
                    ['email' => 'a2@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA2],
                ],
            ],
        ])->toArray();

        $tester = new CommandTester((new Application(static::$kernel))->find('reporting:executer-rapports'));
        self::assertSame(0, $tester->execute([]));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $rapportEntite = $em->getRepository(RapportPlanifie::class)->find($rapport['id']);
        self::assertNotNull($rapportEntite);
        self::assertNotNull($rapportEntite->getDernierEnvoi(), 'CA-7 : rapport actif généré/envoyé sans connexion du créateur.');

        /** @var list<Export> $exports */
        $exports = $em->getRepository(Export::class)->findBy(['rapportPlanifie' => $rapportEntite]);
        self::assertCount(2, $exports, 'CA-8 : un Export par destinataire.');
        foreach ($exports as $export) {
            // ⚠ `NonExpedie` ET NON `Envoye`, ET CE TEST AFFIRMAIT L'INVERSE DEPUIS L'ORIGINE.
            // `app/.env` porte `MAILER_DSN=null://null` : le harnais n'a jamais expedie un seul
            // courriel. L'ancienne assertion etait verte pour la raison meme qui rendait le produit
            // faux — `null://` n'echoue pas, donc la commande deduisait « envoye » de « aucune
            // exception ». Le vert ne mesurait pas l'envoi, il mesurait l'absence d'erreur.
            self::assertSame(StatutExport::NonExpedie, $export->getStatut());
            // Et c'est `envoyeLe` qui rend le faux impossible a fabriquer plus tard : une ligne
            // non expediee n'a pas d'horodatage, donc aucune requete ne la confondra avec un envoi.
            self::assertNull($export->getEnvoyeLe(), 'un rapport non expedie n a pas de date d envoi');
            // CA-8 : chaque Export est borné au périmètre de SON destinataire (pas « région » au sens large).
            self::assertNotNull($export->getEtablissement());
        }
        $etablissementsExportes = array_map(static fn (Export $e): string => (string) $e->getEtablissement()?->getId(), $exports);
        self::assertContains($idA1, $etablissementsExportes);
        self::assertContains($idA2, $etablissementsExportes);
    }

    /**
     * TEMOIN POSITIF : avec un transport declare REEL, la commande atteint encore `Envoye`.
     *
     * ⚠ SANS CE TEST, UN CODE QUI RENDRAIT TOUJOURS `NonExpedie` SERAIT VERT. Les deux autres
     * tests ne regardent que le cas nul ; celui-ci est le seul qui prouve que le chemin d'envoi
     * existe toujours. Un detecteur se prouve aussi par ce qu'il n'attrape pas.
     *
     * Le transport du harnais reste `null://` — on ne fabrique pas un vrai envoi, on declare
     * seulement que le transport en serait un. C'est exactement la question que la commande pose.
     */
    public function testAvecUnTransportReelLeStatutRedevientEnvoye(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);
        $tdb = $this->creerTableauDeBord('region', '/api/regions/' . $this->idRegion(L11Fixtures::REGION_A_NOM));

        // ⚠ `heureEnvoi` A '00:00' N'EST PAS DECORATIF. Le defaut est '07:00' : sans cette ligne,
        // `prochainEnvoi` n'est pas echu et la commande saute le rapport — a bon droit. Le test
        // etait alors rouge en accusant le code, alors qu'il accusait son propre jeu de donnees.
        // Et `$entete` est DEJA un tableau d'options (`authRegion()` le rend ainsi) : il se
        // fusionne, il ne se niche pas sous une clef `headers`.
        $client->request('POST', '/api/rapport_planifies', $entete + [
            'json' => [
                'nom' => 'Rapport transport reel',
                'tableauDeBord' => $tdb,
                'format' => 'csv',
                'periodicite' => 'quotidienne',
                'heureEnvoi' => '00:00',
                'destinataires' => [
                    ['email' => 'reel@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA1],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        static::getContainer()->set(TransportCourrielInterface::class, new class implements TransportCourrielInterface {
            public function estReel(): bool
            {
                return true;
            }

            public function raison(): ?string
            {
                return null;
            }
        });

        $tester = new CommandTester((new Application(static::$kernel))->find('reporting:executer-rapports'));
        self::assertSame(0, $tester->execute([]));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var list<Export> $exports */
        $exports = $em->getRepository(Export::class)->findBy(['destinataireEmail' => 'reel@itcotation.com']);
        self::assertNotEmpty($exports, 'le rapport a bien ete traite');
        foreach ($exports as $export) {
            self::assertSame(StatutExport::Envoye, $export->getStatut());
            self::assertNotNull($export->getEnvoyeLe(), 'un envoi reel porte son horodatage');
        }
    }

    /**
     * La regle du fichier vit sur l'enum, et elle est verifiee sur TOUS ses cas.
     *
     * ⚠ LA BOUCLE PORTE UN TEMOIN DE CARDINALITE. Sans le `assertCount`, retirer un cas de l'enum
     * laisserait ce test vert en ne mesurant plus rien du cas disparu — et en ajouter un le
     * laisserait vert aussi, alors que c'est precisement l'ajout d'un cas qui a casse les deux
     * portes de telechargement le 15/09.
     */
    public function testSeulUnEchecNaPasDeFichier(): void
    {
        self::assertCount(4, StatutExport::cases(), 'quatre statuts : genere, envoye, non_expedie, echec');

        foreach (StatutExport::cases() as $cas) {
            self::assertSame(
                $cas !== StatutExport::Echec,
                $cas->fichierDisponible(),
                sprintf('le statut « %s » se trompe sur la presence d un fichier', $cas->value),
            );
        }
    }

    public function testRapportSuspenduNestJamaisGenere(): void
    {
        $this->agreger();
        [$client, $entete] = $this->authRegion();
        $idA1 = $this->idEtablissement(L11Fixtures::SITE_A1_NOM);
        $tdb = $this->creerTableauDeBord('etablissement', '/api/etablissements/' . $idA1);

        $rapport = $client->request('POST', '/api/rapport_planifies', $entete + [
            'json' => [
                'nom' => 'Rapport suspendu',
                'tableauDeBord' => $tdb,
                'format' => 'csv',
                'periodicite' => 'quotidienne',
                'heureEnvoi' => '00:00',
                'etat' => 'suspendu',
                'destinataires' => [['email' => 'a1@itcotation.com', 'niveau' => 'etablissement', 'etablissement' => '/api/etablissements/' . $idA1]],
            ],
        ])->toArray();

        $tester = new CommandTester((new Application(static::$kernel))->find('reporting:executer-rapports'));
        $tester->execute([]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $rapportEntite = $em->getRepository(RapportPlanifie::class)->find($rapport['id']);
        self::assertNotNull($rapportEntite);
        self::assertNull($rapportEntite->getDernierEnvoi(), 'Un rapport suspendu ne doit jamais être généré (CA-7).');

        $exports = $em->getRepository(Export::class)->findBy(['rapportPlanifie' => $rapportEntite]);
        self::assertCount(0, $exports);
    }
}
