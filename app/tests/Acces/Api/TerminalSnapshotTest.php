<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Tests\Acces\AccesApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Snapshot local incrémental/complet (GET /terminal/snapshot, US-TERM-03/04/05, CA-5/6, §4.3 spec).
 */
final class TerminalSnapshotTest extends AccesApiTestCase
{
    public function testCa5SnapshotCompletPuisDeltaVide(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        $complet = $client->request('GET', '/api/terminal/snapshot', $entete)->toArray();
        self::assertGreaterThan(0, $complet['versionCourante']);
        self::assertNotEmpty($complet['entrees']);
        self::assertContains(AccesFixtures::SUPPORT_IDENTIFIANT, array_column($complet['entrees'], 'identifiant'));

        $delta = $client->request('GET', '/api/terminal/snapshot', $entete + ['query' => ['depuis' => $complet['versionCourante']]])->toArray();
        self::assertSame([], $delta['entrees'], 'Delta correct : pas de sur-transmission sans mutation entre-temps.');
    }

    public function testCa6SupportBloqueApparaitEnTombstoneAuDeltaSuivant(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        $complet = $client->request('GET', '/api/terminal/snapshot', $entete)->toArray();
        $curseur = $complet['versionCourante'];

        [$adminClient, $adminEntete] = $this->adminSurA();
        $blocage = $adminClient->request('POST', '/api/acces/supports/' . $this->idSupport() . '/bloquer', $adminEntete + [
            'json' => ['motif' => 'Test CA-6 tombstone snapshot terminal'],
        ]);
        self::assertContains($blocage->getStatusCode(), [200, 201], (string) $blocage->getContent(false));

        $delta = $client->request('GET', '/api/terminal/snapshot', $entete + ['query' => ['depuis' => $curseur]])->toArray();
        self::assertNotEmpty($delta['entrees']);
        $entree = current(array_filter($delta['entrees'], static fn (array $e): bool => $e['identifiant'] === AccesFixtures::SUPPORT_IDENTIFIANT));
        self::assertNotFalse($entree, 'Le support bloqué doit apparaître au delta (tombstone).');
        self::assertTrue($entree['revoque']);
    }

    public function testPaginationSnapshotCompletJusquaStableEntrePages(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        // Support de démonstration + au moins 2 supports additionnels pour un lot de 3 (etab A).
        $this->creerSupportsSupplementaires(2);

        $page1 = $client->request('GET', '/api/terminal/snapshot', $entete + ['query' => ['taille' => 1, 'page' => 1]])->toArray();
        self::assertCount(1, $page1['entrees']);
        self::assertTrue($page1['pageSuivante']);
        self::assertSame(1, $page1['taillepage']);

        $page2 = $client->request('GET', '/api/terminal/snapshot', $entete + ['query' => ['taille' => 1, 'page' => 2, 'jusqua' => $page1['versionCourante']]])->toArray();
        self::assertCount(1, $page2['entrees']);
        self::assertSame($page1['versionCourante'], $page2['versionCourante'], 'jusqua stable entre pages (pas de dérive pendant la pagination).');
        self::assertNotSame($page1['entrees'][0]['identifiant'], $page2['entrees'][0]['identifiant']);
    }

    /**
     * Durcissement revue sécurité (contrat §5) : `numeroBillet` doit être dérivé du `BilletSupport`
     * référencé par le `DroitAcces`, comme le fait déjà `AffichagePorteurResolver` pour le flux en
     * ligne — au lieu de rester systématiquement `null` dans le snapshot.
     */
    public function testNumeroBilletDeriveDuBilletSupportPourUnSupportBillette(): void
    {
        [$identifiant, $numeroAttendu] = $this->creerSupportBillete();

        $entete = $this->terminalEntete();
        $client = static::createClient();
        $complet = $client->request('GET', '/api/terminal/snapshot', $entete)->toArray();

        $entree = current(array_filter($complet['entrees'], static fn (array $e): bool => $e['identifiant'] === $identifiant));
        self::assertNotFalse($entree, 'Le support billetté doit apparaître dans le snapshot.');
        self::assertSame($numeroAttendu, $entree['numeroBillet']);
    }

    /** @return array{0: string, 1: string} identifiant du Support, numéro de vente attendu */
    private function creerSupportBillete(): array
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $admin = $this->entite(\App\Securite\Entity\Utilisateur::class, ['email' => \App\DataFixtures\SocleFixtures::ADMIN_EMAIL]);

        $pdv = (new \App\Caisse\Entity\PointDeVente())->setLibelle('PDV test snapshot billet')->setEtablissement($etab);
        $em->persist($pdv);
        $caisse = (new \App\Caisse\Entity\Caisse())->setLibelle('Caisse test snapshot billet')->setPointDeVente($pdv)->setEtat(\App\Caisse\Enum\EtatCaisse::Ouverte);
        $em->persist($caisse);
        $session = (new \App\Caisse\Entity\SessionCaisse())->setNumero('S-SNAP-' . uniqid())->setPointDeVente($pdv)->setCaisse($caisse)
            ->setRegisseur($admin)->setOperateur($admin)->setFondDeCaisse('0.00')->setEtablissement($etab);
        $em->persist($session);
        $numero = 'V-SNAP-' . uniqid();
        $vente = (new \App\Vente\Entity\Vente())->setNumero($numero)->setSession($session)->setEtablissement($etab);
        $em->persist($vente);

        $billetSupport = (new \App\Vente\Entity\BilletSupport())
            ->setVente($vente)
            ->setType(\App\Vente\Enum\TypeSupport::Billet)
            ->setIdentifiantSupport('BIL-SNAP-' . uniqid());
        $em->persist($billetSupport);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::Billet)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($etab)
            ->setBilletSupportRef($billetSupport->getId());
        $em->persist($droit);

        $identifiant = 'SNAP-BILLET-' . substr((string) Uuid::v4(), 0, 8);
        $support = new Support();
        $support->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($support);

        $appairage = new Appairage();
        $appairage->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab);
        $em->persist($appairage);

        $sequencer = static::getContainer()->get(\App\Acces\Service\VersionSnapshotSequencer::class);
        $support->setVersionMaj($sequencer->suivant());

        $em->flush();

        return [$identifiant, $numero];
    }

    private function creerSupportsSupplementaires(int $n): void
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);

        for ($i = 0; $i < $n; ++$i) {
            $support = new \App\Acces\Entity\Support();
            $support->setIdentifiant('SNAP-DEMO-' . $i . '-' . substr((string) \Symfony\Component\Uid\Uuid::v4(), 0, 8))
                ->setType(\App\Acces\Enum\TypeSupport::Qr)
                ->setEtablissement($etab)
                ->setVersionMaj(0);
            $em->persist($support);
        }
        $em->flush();

        // Chaque support additionnel doit porter une version distincte pour être paginé de façon
        // stable : on les fait passer par un blocage/déblocage pour leur attribuer une versionMaj via
        // le séquenceur applicatif (évite d'accéder directement au service interne depuis le test).
        $sequencer = static::getContainer()->get(\App\Acces\Service\VersionSnapshotSequencer::class);
        foreach ($em->getRepository(\App\Acces\Entity\Support::class)->findBy(['etablissement' => $etab]) as $support) {
            $support->setVersionMaj($sequencer->suivant());
        }
        $em->flush();
    }
}
