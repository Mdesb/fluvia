<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Unit;

use App\Tests\SchemaDuHarnais;
use App\Caution\Entity\GrilleRetenue as GrilleRetenueGenerique;
use App\Caution\Enum\ModeRetenue as ModeRetenueGenerique;
use App\Caution\Service\GestionCaution;
use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\DataFixtures\PatinoireFixtures;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Enum\MotifRetenue;
use App\Patinoire\State\GrilleRetenueProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Résolution de la grille de retenue applicable (§4.4) : priorité pointure > établissement. Depuis le
 * refactor caution générique, la résolution est portée par `App\Caution\Service\GestionCaution::
 * resoudreGrille()` (patron générique, `App\Patinoire\Service\ResolveurGrilleRetenueHandler` retiré,
 * fine délégation `App\Patinoire\ApiResource\GrilleRetenue`).
 */
final class ResolveurGrilleRetenueHandlerTest extends KernelTestCase
{
    public function testPrioritePointureSurEtablissement(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        foreach ([SocleFixtures::class, CrmFixtures::class, PatinoireFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etablissement);
        $parc42 = $em->getRepository(ParcPatins::class)->findOneBy(['etablissement' => $etablissement, 'pointure' => 42]);
        self::assertNotNull($parc42);

        /** @var GestionCaution $gestion */
        $gestion = $container->get(GestionCaution::class);

        // Seule la grille générale établissement (fixture, motif casse, 15.00) existe : c'est elle qui s'applique.
        $grille = $gestion->resoudreGrille($etablissement, GrilleRetenueProvider::TYPE_CIBLE, MotifRetenue::Casse->value);
        self::assertNotNull($grille);
        self::assertSame('15.00', $grille->getMontantDecimal());
        self::assertNull($grille->getSousCible(), 'Sans règle spécifique, la règle générale établissement s\'applique.');

        // Ajout d'une règle spécifique à la pointure 42 (montant supérieur) : elle doit primer.
        $grilleSpecifique = (new GrilleRetenueGenerique())->setEtablissement($etablissement)
            ->setTypeCible(GrilleRetenueProvider::TYPE_CIBLE)->setSousCible((string) $parc42->getId())
            ->setMotif(MotifRetenue::Casse->value)->setMode(ModeRetenueGenerique::Forfait)->setMontantCentimes(3000);
        $em->persist($grilleSpecifique);
        $em->flush();

        $grillePrioritaire = $gestion->resoudreGrille($etablissement, GrilleRetenueProvider::TYPE_CIBLE, MotifRetenue::Casse->value, (string) $parc42->getId());
        self::assertNotNull($grillePrioritaire);
        self::assertSame('30.00', $grillePrioritaire->getMontantDecimal(), 'Priorité pointure > établissement (§4.4).');
        self::assertSame((string) $parc42->getId(), $grillePrioritaire->getSousCible());

        // Aucune règle pour un autre motif : résolution null.
        $sansGrille = $gestion->resoudreGrille($etablissement, GrilleRetenueProvider::TYPE_CIBLE, MotifRetenue::Perte->value, (string) $parc42->getId());
        self::assertNull($sansGrille);
    }
}
