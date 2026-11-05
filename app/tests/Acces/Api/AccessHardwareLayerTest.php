<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Adapter\SimulateurAccesAdapter;
use App\Acces\Adapter\SimulatedCredentialEncoder;
use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\Passage;
use App\Acces\Entity\Support;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Acces\Hardware\CredentialFormat;
use App\Acces\Hardware\CredentialNormalizer;
use App\Acces\Hardware\HardwareEventIngestor;
use App\Acces\Hardware\HardwareRead;
use App\Acces\Hardware\Simulated\SimulatedTerminal;
use App\Acces\Port\PiloteAcces;
use App\Acces\Service\AppairageHandler;
use App\Acces\Service\BlocageSupportHandler;
use App\Acces\Service\CounterEncodingHandler;
use App\Acces\Service\ValidationPassageHandler;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\Support\KernelTerminalTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Couche d'adaptation matérielle du contrôle d'accès (#337), éprouvée de bout en bout sans matériel.
 *
 * Témoin exigé par le ticket : un titre valide crée un passage, un titre révoqué est refusé — en
 * ligne, hors connexion sur la liste locale, et à la porte d'un pilote. Plus le titre de groupe (un
 * scan, N passages) et la remise d'un badge au comptoir.
 */
final class AccessHardwareLayerTest extends AccesApiTestCase
{
    // ── Terminal simulé, à travers le vrai contrat HTTP /api/terminal ──────────────────────────────

    public function testTerminalEnLigneValideCreeUnPassagePuisRefuseLeTitreRevoque(): void
    {
        $client = static::createClient();
        $terminal = new SimulatedTerminal(new KernelTerminalTransport($client, AccesFixtures::TERMINAL_SECRET));
        self::assertGreaterThan(0, $terminal->synchronize(), 'La liste locale initiale doit contenir le support de démonstration.');

        $equipement = $this->idEquipement();
        $accorde = $terminal->present($equipement, AccesFixtures::SUPPORT_IDENTIFIANT);
        self::assertTrue($accorde->granted, 'Un titre valide doit ouvrir.');
        self::assertSame(1, $this->compterPassages(ResultatPassage::Valide), 'Un titre valide crée exactement un passage.');

        $this->bloquerSupportDemo();

        $refuse = $terminal->present($equipement, AccesFixtures::SUPPORT_IDENTIFIANT);
        self::assertFalse($refuse->granted, 'Un titre révoqué doit être refusé.');
        self::assertSame(CodeMotifRefus::SupportBloque->value, $refuse->reasonCode);
        self::assertSame(1, $this->compterPassages(ResultatPassage::Valide), 'Le refus ne crée aucun passage accepté.');
        self::assertSame(1, $this->compterPassages(ResultatPassage::Refuse), 'Le refus est journalisé.');
    }

    public function testTerminalHorsConnexionRefuseLeTitreRevoqueDepuisSaListeLocale(): void
    {
        $client = static::createClient();
        $terminal = new SimulatedTerminal(new KernelTerminalTransport($client, AccesFixtures::TERMINAL_SECRET));
        $terminal->synchronize();

        $this->bloquerSupportDemo();
        $terminal->synchronize();
        self::assertTrue($terminal->localEntry(AccesFixtures::SUPPORT_IDENTIFIANT)['revoque'] ?? false, 'Le delta doit porter la révocation.');

        $terminal->disconnect();
        $decision = $terminal->present($this->idEquipement(), AccesFixtures::SUPPORT_IDENTIFIANT);

        self::assertFalse($decision->granted);
        self::assertSame(CodeMotifRefus::SupportBloque->value, $decision->reasonCode);
        self::assertSame(0, $terminal->pendingCount(), 'Un refus local ne part jamais en file.');
    }

    public function testTerminalHorsConnexionAccepteLocalementPuisRemonteLePassageAuRetourDuReseau(): void
    {
        $client = static::createClient();
        $terminal = new SimulatedTerminal(new KernelTerminalTransport($client, AccesFixtures::TERMINAL_SECRET));
        $terminal->synchronize();
        $terminal->disconnect();

        $decision = $terminal->present($this->idEquipement(), AccesFixtures::SUPPORT_IDENTIFIANT);
        self::assertTrue($decision->granted, 'Sur la liste locale, le titre valide ouvre.');
        self::assertSame(1, $terminal->pendingCount());
        self::assertSame(0, $this->compterPassages(ResultatPassage::Valide), 'Rien n\'est en base tant que la borne est coupée.');

        $compteRendu = $terminal->reconnect();
        self::assertSame('accepte', $compteRendu['resultats'][0]['statut'] ?? null, json_encode($compteRendu));

        $passage = $this->em()->getRepository(Passage::class)->findOneBy(['resultat' => ResultatPassage::Valide]);
        self::assertInstanceOf(Passage::class, $passage);
        self::assertTrue($passage->isOrigineHorsLigne(), 'Le passage remonté est marqué hors ligne.');
    }

    // ── Remontée par un pilote : HardwareEventIngestor ─────────────────────────────────────────────

    public function testTitreDeGroupeCompteNPassagesEtNeLesRecomptePasAuRejeu(): void
    {
        self::bootKernel();
        $droit = $this->droitDemo();
        $creditAvant = (int) $droit->getCreditRestant();
        $droit->setHeadcount(3);
        $this->em()->flush();

        $cle = Uuid::v4();
        $lecture = new HardwareRead(Uuid::fromString($this->idEquipement()), AccesFixtures::SUPPORT_IDENTIFIANT, CredentialFormat::Raw, new \DateTimeImmutable(), $cle, SensPassage::Entree);

        $decision = $this->ingestor()->ingest($lecture);
        self::assertTrue($decision->granted, $decision->message);
        self::assertSame(3, $decision->headcount);
        self::assertSame(3, $this->compterPassages(ResultatPassage::Valide), 'Un scan de groupe compte trois personnes.');
        self::assertSame(2, $this->em()->getRepository(Passage::class)->count(['codeMotif' => CodeMotifRefus::GroupHeadcount]));

        $this->em()->refresh($droit);
        self::assertSame($creditAvant - 3, $droit->getCreditRestant(), 'Une entrée de carte par personne.');

        $jauge = $this->em()->getRepository(JaugeFmi::class)->findOneBy(['espace' => $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE])]);
        self::assertInstanceOf(JaugeFmi::class, $jauge);
        $this->em()->refresh($jauge);
        self::assertSame(3, $jauge->getValeurCourante(), 'La jauge compte les trois personnes.');

        $pilote = static::getContainer()->get(PiloteAcces::class);
        self::assertInstanceOf(SimulateurAccesAdapter::class, $pilote);
        self::assertSame(3, $pilote->ouvertures()[array_key_last($pilote->ouvertures())]['headcount'], 'La porte reçoit l\'ordre de laisser passer trois personnes.');

        // Retransmission de la même lecture : même décision, aucun passage ni crédit de plus.
        $rejeu = $this->ingestor()->ingest($lecture);
        self::assertTrue($rejeu->granted);
        self::assertSame(3, $this->compterPassages(ResultatPassage::Valide));
        $this->em()->refresh($droit);
        self::assertSame($creditAvant - 3, $droit->getCreditRestant());
    }

    public function testTitreDeGroupeRefuseQuandLaCarteNaPasAssezDEntrees(): void
    {
        self::bootKernel();
        $droit = $this->droitDemo();
        $droit->setCreditRestant(2)->setHeadcount(3);
        $this->em()->flush();

        $decision = $this->ingestor()->ingest(new HardwareRead(Uuid::fromString($this->idEquipement()), AccesFixtures::SUPPORT_IDENTIFIANT, CredentialFormat::Raw, new \DateTimeImmutable(), Uuid::v4(), SensPassage::Entree));

        self::assertFalse($decision->granted);
        self::assertSame(CodeMotifRefus::CreditEpuise->value, $decision->reasonCode);
        self::assertSame(0, $this->compterPassages(ResultatPassage::Valide));
    }

    public function testLeSnapshotPorteLeNombreDePersonnesDuTitre(): void
    {
        self::bootKernel();
        $this->droitDemo()->setHeadcount(4);
        $this->em()->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $terminal = new SimulatedTerminal(new KernelTerminalTransport($client, AccesFixtures::TERMINAL_SECRET));
        $terminal->synchronize();
        self::assertSame(4, $terminal->localEntry(AccesFixtures::SUPPORT_IDENTIFIANT)['headcount'] ?? null);

        $terminal->disconnect();
        $decision = $terminal->present($this->idEquipement(), AccesFixtures::SUPPORT_IDENTIFIANT);
        self::assertTrue($decision->granted);
        self::assertSame(4, $decision->headcount, 'Hors connexion, la borne accorde quatre rotations.');
    }

    // ── Comptoir : encodage d'un badge existant, reconnu à la porte sous une autre forme ──────────

    public function testBadgeEncodeAuComptoirEstReconnuALaPorteQuelQueSoitLeFormatDuLecteur(): void
    {
        self::bootKernel();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $droit = $this->nouveauDroit($etabA, withDemoSpace: true);

        $encodeur = new SimulatedCredentialEncoder();
        // Badge existant du client, lu en décimal par le lecteur USB du comptoir.
        $encodeur->place('comptoir-1', '77705923', CredentialFormat::Decimal);
        $appairage = $this->comptoir($encodeur)->issue('comptoir-1', $droit, $etabA, TypeSupport::Rfid, 2);

        self::assertSame('04A1B2C3', $appairage->getSupport()?->getIdentifiant(), 'Forme canonique : hexadécimal, octet de poids fort en tête.');
        self::assertSame(2, $encodeur->written()['77705923']->headcount ?? null, 'L\'autorisation écrite porte le nombre de personnes.');

        // À la porte, le lecteur rend le même badge en hexadécimal inversé, avec séparateurs.
        $porte = $this->ingestor()->ingest(new HardwareRead(Uuid::fromString($this->idEquipement()), 'C3:B2:A1:04', CredentialFormat::HexLsbFirst, new \DateTimeImmutable(), Uuid::v4(), SensPassage::Entree));
        self::assertTrue($porte->granted, $porte->message);
        self::assertSame(2, $porte->headcount);

        // Révocation : le même badge est refusé au passage suivant.
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);
        static::getContainer()->get(BlocageSupportHandler::class)->bloquer($appairage->getSupport(), 'Bracelet perdu', $admin);
        $apres = $this->ingestor()->ingest(new HardwareRead(Uuid::fromString($this->idEquipement()), 'C3B2A104', CredentialFormat::HexLsbFirst, new \DateTimeImmutable(), Uuid::v4(), SensPassage::Entree));
        self::assertFalse($apres->granted);
        self::assertSame(CodeMotifRefus::SupportBloque->value, $apres->reasonCode);
    }

    public function testLeComptoirNeRemetPasDeBadgePourLeDroitDUnAutreEtablissement(): void
    {
        self::bootKernel();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $droitB = $this->nouveauDroit($etabB, withDemoSpace: false);

        $encodeur = new SimulatedCredentialEncoder();
        $encodeur->place('comptoir-1', '0A0B0C0D');

        try {
            $this->comptoir($encodeur)->issue('comptoir-1', $droitB, $etabA);
            self::fail('Un comptoir de A ne doit pas remettre un badge pour un droit de B.');
        } catch (NotFoundHttpException) {
        }
        self::assertNull($this->em()->getRepository(Support::class)->findOneBy(['identifiant' => '0A0B0C0D']), 'Aucun support créé.');

        // Témoin : le même geste, depuis le comptoir de B, aboutit.
        $appairage = $this->comptoir($encodeur)->issue('comptoir-1', $droitB, $etabB);
        self::assertTrue($appairage->isActif());
        self::assertSame((string) $etabB->getId(), (string) $appairage->getEtablissement()?->getId());
    }

    public function testUnPosteQuiNEcritPasAppaireLeNumeroDeSerieSeul(): void
    {
        self::bootKernel();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $droit = $this->nouveauDroit($etabA, withDemoSpace: true);

        $encodeur = new SimulatedCredentialEncoder();
        $encodeur->refuseWrites();
        $encodeur->place('comptoir-1', '04a1b2c3d4e5f6');
        $appairage = $this->comptoir($encodeur)->issue('comptoir-1', $droit, $etabA);

        self::assertSame('04A1B2C3D4E5F6', $appairage->getSupport()?->getIdentifiant());
        self::assertSame([], $encodeur->written(), 'Rien n\'est écrit sur la carte.');
        self::assertInstanceOf(Appairage::class, $this->em()->getRepository(Appairage::class)->findOneBy(['support' => $appairage->getSupport(), 'actif' => true]));
    }

    // ── Écran Accès : appairage avec format du lecteur et nombre de personnes ────────────────────

    public function testAppairageParLApiNormaliseLeBadgeEtDeclareUnTitreDeGroupe(): void
    {
        self::bootKernel();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $droitId = (string) $this->nouveauDroit($etabA, withDemoSpace: true)->getId();
        self::ensureKernelShutdown();

        [$client, $entete] = $this->adminSurA();
        $reponse = $client->request('POST', '/api/acces/appairages', $entete + ['json' => [
            'identifiantSupport' => 'C3:B2:A1:04',
            'format' => 'hex_lsb_first',
            'typeSupport' => 'RFID',
            'droit' => $droitId,
            'headcount' => 5,
        ]]);
        self::assertSame(201, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $droit = $this->entite(DroitAcces::class, ['id' => Uuid::fromString($droitId)]);
        self::assertSame(5, $droit->getHeadcount());
        $this->entite(Support::class, ['identifiant' => '04A1B2C3']);

        // Témoin de refus : 0 personne est une saisie erronée, rien n'est enregistré.
        $refus = $client->request('POST', '/api/acces/appairages', $entete + ['json' => [
            'identifiantSupport' => 'AABBCCDD', 'typeSupport' => 'RFID', 'droit' => $droitId, 'headcount' => 0,
        ]]);
        self::assertSame(422, $refus->getStatusCode());
        self::assertNull($this->em()->getRepository(Support::class)->findOneBy(['identifiant' => 'AABBCCDD']));
    }

    // ── Outillage ───────────────────────────────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function ingestor(): HardwareEventIngestor
    {
        return new HardwareEventIngestor(static::getContainer()->get(ValidationPassageHandler::class), new CredentialNormalizer(), $this->em());
    }

    private function comptoir(SimulatedCredentialEncoder $encodeur): CounterEncodingHandler
    {
        return new CounterEncodingHandler($encodeur, new CredentialNormalizer(), static::getContainer()->get(AppairageHandler::class));
    }

    /** Le droit appairé au support de démonstration — jamais `findOneBy([])` (ordre aléatoire sur UUID). */
    private function droitDemo(): DroitAcces
    {
        $support = $this->entite(Support::class, ['identifiant' => AccesFixtures::SUPPORT_IDENTIFIANT]);
        $droit = $this->entite(Appairage::class, ['support' => $support, 'actif' => true])->getDroit();
        self::assertInstanceOf(DroitAcces::class, $droit);

        return $droit;
    }

    private function nouveauDroit(Etablissement $etablissement, bool $withDemoSpace): DroitAcces
    {
        $droit = (new DroitAcces())->setSourceType(TypeDroitAcces::Billet)->setEtablissement($etablissement);
        if ($withDemoSpace) {
            $droit->addAuthorisedSpace($this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE]));
        }
        $this->em()->persist($droit);
        $this->em()->flush();

        return $droit;
    }

    private function bloquerSupportDemo(): void
    {
        [$adminClient, $adminEntete] = $this->adminSurA();
        $reponse = $adminClient->request('POST', '/api/acces/supports/' . $this->idSupport() . '/bloquer', $adminEntete + [
            'json' => ['motif' => 'Témoin #337 : titre révoqué'],
        ]);
        self::assertContains($reponse->getStatusCode(), [200, 201], (string) $reponse->getContent(false));
    }

    private function compterPassages(ResultatPassage $resultat): int
    {
        // `count()` est une requête SQL : elle lit la base, pas l'unité de travail.
        $support = $this->entite(Support::class, ['identifiant' => AccesFixtures::SUPPORT_IDENTIFIANT]);

        return $this->em()->getRepository(Passage::class)->count(['support' => $support, 'resultat' => $resultat]);
    }
}
