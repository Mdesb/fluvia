<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Tests\SchemaDuHarnais;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Compta\Service\LettrageHandler;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * RG-M6-14 : `lettrer()` (existant) reste strictement inchangé (non-régression explicite) ;
 * `lettrerGroupe()` (nouveau) rapproche plusieurs lignes en un seul geste avec un `reconciliationCode`
 * partagé.
 */
final class LettrageHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LettrageHandler $handler;
    private ScellementEcritureHandler $scellement;
    private ProfilExploitant $profil;
    private Journal $journal;
    private CompteComptable $compteFournisseur;
    private CompteComptable $compteBanque;
    private TauxTva $taux;
    private Utilisateur $auteur;
    private \App\Compta\Entity\PeriodeComptable $periode;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        foreach ([SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        /** @var LettrageHandler $handler */
        $handler = $container->get(LettrageHandler::class);
        $this->handler = $handler;
        /** @var ScellementEcritureHandler $scellement */
        $scellement = $container->get(ScellementEcritureHandler::class);
        $this->scellement = $scellement;

        $this->profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
        $this->journal = $em->getRepository(Journal::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'code' => 'OD']);
        $this->compteFournisseur = $em->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'numero' => '401000']);
        $this->compteBanque = $em->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'numero' => '512000']);
        $this->taux = $em->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'libelle' => TauxTva::LIBELLE_HORS_CHAMP]);
        $this->auteur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);

        $periode = new \App\Compta\Entity\PeriodeComptable();
        $periode->setProfilExploitant($this->profil);
        $periode->setDateDebut(new \DateTimeImmutable('2026-08-01'));
        $periode->setDateFin(new \DateTimeImmutable('2026-08-31'));
        $em->persist($periode);
        $em->flush();
        $this->periode = $periode;
    }

    public function testLettrerSimpleInchangeNonRegression(): void
    {
        $ligne = $this->ligneScellee(1000, 0);

        $lettrage = $this->handler->lettrer($ligne, $this->auteur);

        self::assertNull($lettrage->getReconciliationCode(), 'Le lettrage simple ne porte jamais de reconciliationCode (RG-M6-14).');
        self::assertSame($ligne, $lettrage->getLigne());
        self::assertSame($this->auteur, $lettrage->getAuteur());
    }

    public function testLettrerGroupeDeuxLignesMemeMontantMemeReconciliationCode(): void
    {
        $ligneCredit = $this->ligneScellee(0, 1000);
        $ligneDebit = $this->ligneScellee(1000, 0);

        $lettrages = $this->handler->lettrerGroupe([$ligneCredit, $ligneDebit], $this->auteur);

        self::assertCount(2, $lettrages);
        self::assertNotNull($lettrages[0]->getReconciliationCode());
        self::assertSame($lettrages[0]->getReconciliationCode(), $lettrages[1]->getReconciliationCode());
    }

    public function testLettrerGroupeDesequilibreRejete422(): void
    {
        $ligneCredit = $this->ligneScellee(0, 1000);
        $ligneDebit = $this->ligneScellee(500, 0);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->handler->lettrerGroupe([$ligneCredit, $ligneDebit], $this->auteur);
    }

    public function testLettrerGroupeMoinsDeDeuxLignesRejete(): void
    {
        $ligne = $this->ligneScellee(1000, 0);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->handler->lettrerGroupe([$ligne], $this->auteur);
    }

    public function testLettrerGroupeLigneNonScelleeRejetee409(): void
    {
        $ecriture = new EcritureComptable();
        $ecriture->setProfilExploitant($this->profil);
        $ecriture->setJournal($this->journal);
        $ecriture->setPeriode($this->periode);
        $ecriture->setDateEcriture(new \DateTimeImmutable());
        $ecriture->setStatut(StatutEcriture::Provisoire);
        $ligneNonScellee = (new LigneEcriture())->setCompte($this->compteBanque)->setDebitCentimes(1000)->setTauxTva($this->taux);
        $ecriture->addLigne($ligneNonScellee);
        $this->em->persist($ecriture);
        $this->em->flush();

        $ligneScellee = $this->ligneScellee(0, 1000);

        $this->expectException(ConflictHttpException::class);
        $this->handler->lettrerGroupe([$ligneNonScellee, $ligneScellee], $this->auteur);
    }

    public function testLettrerGroupeLigneDejaLettreeRejetee409(): void
    {
        $ligneCredit = $this->ligneScellee(0, 1000);
        $ligneDebit = $this->ligneScellee(1000, 0);
        $this->handler->lettrer($ligneCredit, $this->auteur);

        $ligneDebit2 = $this->ligneScellee(1000, 0);

        $this->expectException(ConflictHttpException::class);
        $this->handler->lettrerGroupe([$ligneCredit, $ligneDebit2], $this->auteur);
    }

    private function ligneScellee(int $debitCentimes, int $creditCentimes): LigneEcriture
    {
        $ecriture = new EcritureComptable();
        $ecriture->setProfilExploitant($this->profil);
        $ecriture->setJournal($this->journal);
        $ecriture->setPeriode($this->periode);
        $ecriture->setDateEcriture(new \DateTimeImmutable());
        $ecriture->setStatut(StatutEcriture::Controlee);

        $ligne = (new LigneEcriture())
            ->setCompte($debitCentimes > 0 ? $this->compteBanque : $this->compteFournisseur)
            ->setDebitCentimes($debitCentimes)
            ->setCreditCentimes($creditCentimes)
            ->setTauxTva($this->taux);
        $ecriture->addLigne($ligne);

        $this->scellement->sceller($ecriture);
        $this->em->persist($ecriture);
        $this->em->flush();

        return $ligne;
    }
}
