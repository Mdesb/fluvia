<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\MoyenPaiement;
use App\Compta\Entity\PaymentMethodTreasuryAccount;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\SensCompte;
use App\Compta\Enum\StatutPeriode;
use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Etablissement;
use App\Compta\Service\PaymentLedgerPoster;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\SchemaDuHarnais;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * G-2 / G-3 — l'écriture d'encaissement au journal `ENC`.
 *
 * ⚠ CE QUE CES TESTS DOIVENT PROUVER, ET QUI NE VA PAS DE SOI. Un contrôle qui refuse se prouve mal :
 * un refus universel passerait `testMoyenSansCompteDeTresorerieRefuse` haut la main. C'est le cas
 * qu'il doit AUTORISER qui le démasque — d'où `testMoyenConfigurePasse`, écrit en regard, avec le
 * même appel et le seul compte de trésorerie qui change.
 */
final class PaymentLedgerPosterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PaymentLedgerPoster $poster;
    private ProfilExploitant $profil;
    private CompteComptable $compteClient;
    private CompteComptable $compteBanque;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        SchemaDuHarnais::reinitialiser($em);

        foreach ([SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        /** @var PaymentLedgerPoster $poster */
        $poster = $container->get(PaymentLedgerPoster::class);
        $this->poster = $poster;

        $this->profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
        $this->compteClient = $em->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'numero' => '411000']);
        $this->compteBanque = $em->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'numero' => '512000']);

        $this->periodeOuverte();
    }

    public function testEcritureAuJournalEncaissementsEquilibreeEtScellee(): void
    {
        $moyen = $this->moyen('virement', $this->compteBanque);

        $ecriture = $this->poster->post(
            $this->profil,
            $moyen,
            36000,
            new \DateTimeImmutable('2026-08-19'),
            $this->compteClient,
            'Encaissement FA-2026-00001',
            counterpartyType: 'crm_client',
            counterpartyLabel: 'Dupont',
        );

        self::assertSame('ENC', $ecriture->getJournal()?->getCode(), 'Le journal des encaissements existait et n\'avait jamais reçu d\'écriture.');
        self::assertTrue($ecriture->estEquilibree());
        self::assertTrue($ecriture->estScellee());

        $lignes = $ecriture->getLignes()->toArray();
        self::assertCount(2, $lignes);

        $debit = $this->ligneParCompte($lignes, '512000');
        $credit = $this->ligneParCompte($lignes, '411000');
        self::assertSame(36000, $debit->getDebitCentimes(), 'La trésorerie entre.');
        self::assertSame(36000, $credit->getCreditCentimes(), 'La créance s\'éteint.');
        self::assertSame('crm_client', $credit->getCounterpartyType(), 'La contrepartie se porte sur la ligne de tiers, pas sur la trésorerie.');
        self::assertNull($debit->getCounterpartyType());
    }

    /**
     * L'écart assumé avec le chemin fournisseur, verrouillé ici : il EMPRUNTE le taux de la ligne 401,
     * ce qui attribuerait 20 % de TVA à un mouvement qui n'en porte aucune dès qu'un état groupe par
     * taux. Un encaissement est hors champ.
     */
    public function testLaLigneEstHorsChampEtNEmprunteAucunTaux(): void
    {
        $ecriture = $this->poster->post(
            $this->profil,
            $this->moyen('virement', $this->compteBanque),
            1000,
            new \DateTimeImmutable('2026-08-19'),
            $this->compteClient,
            'Encaissement',
        );

        foreach ($ecriture->getLignes() as $ligne) {
            self::assertSame(TauxTva::LIBELLE_HORS_CHAMP, $ligne->getTauxTva()?->getLibelle());
        }
    }

    public function testMoyenSansCompteDeTresorerieRefuseEtLeNomme(): void
    {
        $moyen = $this->moyen('especes', null);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/especes/');

        $this->poster->post(
            $this->profil,
            $moyen,
            1000,
            new \DateTimeImmutable('2026-08-19'),
            $this->compteClient,
            'Encaissement',
        );
    }

    /**
     * ⚠ LE TÉMOIN QUI DÉMASQUE UN CONTRÔLE TROP LARGE. Sans lui, un `post()` qui refuserait TOUT
     * ferait passer le test de refus ci-dessus — et paraîtrait même plus sûr.
     */
    public function testMoyenConfigurePasse(): void
    {
        $moyen = $this->moyen('especes', $this->compteBanque);

        $ecriture = $this->poster->post(
            $this->profil,
            $moyen,
            1000,
            new \DateTimeImmutable('2026-08-19'),
            $this->compteClient,
            'Encaissement',
        );

        self::assertTrue($ecriture->estEquilibree());
    }

    public function testPeriodeClotureeRefusee(): void
    {
        $periode = $this->em->getRepository(PeriodeComptable::class)->findOneBy(['profilExploitant' => $this->profil->getId()]);
        $periode->setStatut(StatutPeriode::Cloturee);
        $this->em->flush();

        $this->expectException(ConflictHttpException::class);

        $this->poster->post(
            $this->profil,
            $this->moyen('virement', $this->compteBanque),
            1000,
            new \DateTimeImmutable('2026-08-19'),
            $this->compteClient,
            'Encaissement',
        );
    }

    public function testMontantNonPositifRefuse(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->poster->post(
            $this->profil,
            $this->moyen('virement', $this->compteBanque),
            0,
            new \DateTimeImmutable('2026-08-19'),
            $this->compteClient,
            'Encaissement',
        );
    }

    /**
     * Rattache un compte de trésorerie au couple (profil courant, moyen) — ou le laisse sans compte
     * quand `$compte` est nul.
     */
    private function moyen(string $code, ?CompteComptable $compte, ?ProfilExploitant $profil = null): MoyenPaiement
    {
        $moyen = $this->em->getRepository(MoyenPaiement::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(MoyenPaiement::class, $moyen, sprintf('Le moyen « %s » doit être semé par les fixtures.', $code));

        $profil ??= $this->profil;
        $existant = $this->em->getRepository(PaymentMethodTreasuryAccount::class)
            ->findOneBy(['businessProfile' => $profil->getId(), 'paymentMethod' => $moyen->getId()]);

        if ($compte === null) {
            if ($existant !== null) {
                $this->em->remove($existant);
                $this->em->flush();
            }

            return $moyen;
        }

        $rattachement = $existant ?? new PaymentMethodTreasuryAccount();
        $rattachement->setBusinessProfile($profil)->setPaymentMethod($moyen)->setTreasuryAccount($compte);
        $this->em->persist($rattachement);
        $this->em->flush();

        return $moyen;
    }

    /**
     * ⚠ LE TEST QUI AURAIT ATTRAPÉ LE DÉFAUT, ET QUI N'EXISTAIT PAS.
     *
     * La première version accrochait le compte de trésorerie à `MoyenPaiement` — un référentiel
     * GLOBAL, sans rattachement — alors que les comptes sont par exploitant. Le premier établissement
     * qui configurait « virement » imposait son compte à tous les autres, et leurs encaissements
     * partaient dans SON grand livre.
     *
     * Les six autres tests passaient au vert : ils n'exercent qu'un seul profil exploitant. Un jeu de
     * tests mono-locataire ne peut pas voir un défaut de cloisonnement — il faut deux locataires et le
     * MÊME moyen pour que le partage devienne visible.
     */
    public function testChaqueExploitantEcritSurSonPropreCompteDeTresorerie(): void
    {
        $profilB = $this->second();
        $compteBanqueB = $this->compte($profilB, '512000', 'Banque');
        $compteClientB = $this->compte($profilB, '411000', 'Clients');

        // Le MÊME moyen, deux comptes différents, deux exploitants.
        $this->moyen('virement', $this->compteBanque);
        $this->moyen('virement', $compteBanqueB, $profilB);

        $ecritureA = $this->poster->post($this->profil, $this->moyenVirement(), 1000, new \DateTimeImmutable('2026-08-19'), $this->compteClient, 'A');
        $ecritureB = $this->poster->post($profilB, $this->moyenVirement(), 2000, new \DateTimeImmutable('2026-08-19'), $compteClientB, 'B');

        self::assertSame(
            $this->compteBanque->getId()->toRfc4122(),
            $this->ligneParCompte($ecritureA->getLignes()->toArray(), '512000')->getCompte()?->getId()->toRfc4122(),
        );

        $ligneB = $this->ligneParCompte($ecritureB->getLignes()->toArray(), '512000');
        self::assertSame(
            $compteBanqueB->getId()->toRfc4122(),
            $ligneB->getCompte()?->getId()->toRfc4122(),
            'L\'exploitant B doit écrire sur SON compte de trésorerie, jamais sur celui de A.',
        );
        self::assertNotSame($this->compteBanque->getId()->toRfc4122(), $ligneB->getCompte()?->getId()->toRfc4122());
    }

    private function moyenVirement(): MoyenPaiement
    {
        return $this->em->getRepository(MoyenPaiement::class)->findOneBy(['code' => 'virement']);
    }

    /**
     * Un second exploitant, avec le strict minimum comptable pour pouvoir écrire.
     *
     * Il prend `Patinoire B`, que le socle sème déjà et qu'aucun profil ne couvre : deux exploitants
     * sur deux établissements, ce qui est le cas réel. Les faire partager un établissement aurait
     * fabriqué une situation qui n'existe pas, et le test aurait prouvé autre chose.
     */
    private function second(): ProfilExploitant
    {
        $etabB = $this->em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB, 'Le socle doit semer « Patinoire B ».');

        $profil = new ProfilExploitant();
        $profil->setType(TypeExploitant::RegieDirecte);
        $profil->setReferentielComptable(ReferentielComptable::M57);
        $profil->setSiren('552081317');
        $profil->setEtablissementPrincipal($etabB);
        $profil->addEtablissementRattache($etabB);
        $this->em->persist($profil);

        $this->em->persist((new Journal())->setProfilExploitant($profil)->setCode('ENC')->setLibelle('Journal des encaissements'));
        $this->em->persist((new TauxTva())->setProfilExploitant($profil)->setTaux('0.00')->setLibelle(TauxTva::LIBELLE_HORS_CHAMP)->setActif(true));
        $this->em->flush();

        return $profil;
    }

    private function compte(ProfilExploitant $profil, string $numero, string $libelle): CompteComptable
    {
        $compte = (new CompteComptable())
            ->setProfilExploitant($profil)
            ->setNumero($numero)
            ->setLibelle($libelle)
            ->setSens(SensCompte::Debit);
        $this->em->persist($compte);
        $this->em->flush();

        return $compte;
    }

    /** @param list<\App\Compta\Entity\LigneEcriture> $lignes */
    private function ligneParCompte(array $lignes, string $numero): \App\Compta\Entity\LigneEcriture
    {
        foreach ($lignes as $ligne) {
            if ($ligne->getCompte()?->getNumero() === $numero) {
                return $ligne;
            }
        }

        self::fail(sprintf('Aucune ligne sur le compte %s.', $numero));
    }

    private function periodeOuverte(): PeriodeComptable
    {
        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($this->profil);
        $periode->setDateDebut(new \DateTimeImmutable('2026-08-01'));
        $periode->setDateFin(new \DateTimeImmutable('2026-08-31'));
        $periode->setStatut(StatutPeriode::Ouverte);
        $this->em->persist($periode);
        $this->em->flush();

        return $periode;
    }
}
