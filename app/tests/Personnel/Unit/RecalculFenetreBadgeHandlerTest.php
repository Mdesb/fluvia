<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Unit;

use App\Tests\SchemaDuHarnais;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Acces\Service\AppairageHandler;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\PorteeAccesEmploye;
use App\Personnel\Enum\ModeHoraireBadge;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Personnel\Enum\StatutCreneauTravail;
use App\Personnel\Enum\TypeContrat;
use App\Personnel\Service\RecalculFenetreBadgeHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `RecalculFenetreBadgeHandler` (décision n°3 du plan) : la fenêtre `DroitAcces.fenetreDebut/Fin`
 * suit le prochain/le `CreneauTravail` confirmé en cours de l'employé — approche d'intégration
 * (kernel réel, patron `App\Tests\Acces\Unit\PiloteAccesTest`) plutôt que mock d'EntityManager,
 * le handler reposant fortement sur des requêtes DQL.
 */
final class RecalculFenetreBadgeHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests.
        // Le faire détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test.
        SchemaDuHarnais::reinitialiser($this->em);
    }

    public function testFenetreDeplaceeSurProchainCreneauConfirme(): void
    {
        $etablissement = $this->creerEtablissement();
        $employe = (new Employe())->setNom('Test')->setPrenom('Handler')->setPoste('Agent')
            ->setTypeContrat(TypeContrat::Cdi)->setDateEntree(new \DateTimeImmutable('2024-01-01'));
        $this->em->persist($employe);
        $this->em->flush();

        $badge = $this->emettreBadgeShiftsUniquement($employe, $etablissement);

        // Sans aucun shift : fenêtre dans le passé.
        $handler = static::getContainer()->get(RecalculFenetreBadgeHandler::class);
        $handler->recalculer($badge);
        $this->em->clear();

        $droit = $this->em->getRepository(DroitAcces::class)->find($badge->getDroitAcces()->getId());
        self::assertLessThan(new \DateTimeImmutable(), $droit->getFenetreFin());

        // Un CreneauTravail futur confirmé : la fenêtre se déplace dessus.
        $employeFrais = $this->em->getRepository(Employe::class)->find($employe->getId());
        $etablissementFrais = $this->em->getRepository(Etablissement::class)->find($etablissement->getId());
        $badgeFrais = $this->em->getRepository(BadgeStaff::class)->find($badge->getId());

        // Horodatages sans microsecondes : une colonne DATETIME MariaDB les tronque, ce qui casserait
        // une comparaison stricte après relecture depuis la base si l'on gardait les microsecondes
        // de « now » (héritées par un simple `new \DateTimeImmutable('+2 hours')`).
        $debut = \DateTimeImmutable::createFromFormat('U', (string) (new \DateTimeImmutable('+2 hours'))->getTimestamp());
        $fin = \DateTimeImmutable::createFromFormat('U', (string) (new \DateTimeImmutable('+4 hours'))->getTimestamp());
        self::assertNotFalse($debut);
        self::assertNotFalse($fin);
        $creneau = (new CreneauTravail())->setEtablissement($etablissementFrais)
            ->setLibellePoste('Shift test')->setDebut($debut)->setFin($fin)
            ->setEffectifRequis(1)->setStatut(StatutCreneauTravail::Confirme);
        $this->em->persist($creneau);

        $affectation = (new AffectationTravail())->setCreneauTravail($creneau)->setEmploye($employeFrais)
            ->setStatut(StatutAffectationTravail::Confirmee);
        $this->em->persist($affectation);
        $this->em->flush();

        $handler->recalculer($badgeFrais);
        $this->em->clear();

        $droitApres = $this->em->getRepository(DroitAcces::class)->find($badge->getDroitAcces()->getId());
        self::assertEquals($debut, $droitApres->getFenetreDebut());
        self::assertEquals($fin, $droitApres->getFenetreFin());
    }

    private function emettreBadgeShiftsUniquement(Employe $employe, Etablissement $etablissement): BadgeStaff
    {
        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::Personnel)->setEtablissement($etablissement)
            ->setMargeAvanceDefaut(15)->setMargeRetardDefaut(15);
        $this->em->persist($droit);

        $appairageHandler = static::getContainer()->get(AppairageHandler::class);
        $appairage = $appairageHandler->appairer('BADGE-UNIT-' . substr((string) Uuid::v4(), 0, 8), TypeSupport::Rfid, $droit, ModeAppairage::Caisse, $etablissement);

        $badge = new BadgeStaff();
        $badge->setEmploye($employe)->setEtablissement($etablissement)
            ->setSupport($appairage->getSupport())->setDroitAcces($droit)->setStatut(StatutBadgeStaff::Actif);
        $this->em->persist($badge);

        $portee = new PorteeAccesEmploye();
        $portee->setBadgeStaff($badge)->setModeHoraire(ModeHoraireBadge::ShiftsUniquement)->setMargeAvantApres(15);
        $this->em->persist($portee);

        $this->em->flush();

        return $badge;
    }

    private function creerEtablissement(): Etablissement
    {
        $groupe = (new Groupe())->setNom('Groupe Test Handler');
        $this->em->persist($groupe);
        $region = (new Region())->setNom('Région Test Handler')->setGroupe($groupe);
        $this->em->persist($region);
        $etablissement = (new Etablissement())->setNom('Etab Test Handler')->setRegion($region)->setActif(true);
        $this->em->persist($etablissement);
        $this->em->flush();

        return $etablissement;
    }
}
