<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Tests\SchemaDuHarnais;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\ModeMontantAnnulation;
use App\Reservation\Enum\PorteeRegleAnnulation;
use App\Reservation\Service\ResolveurRegleAnnulation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Priorité de résolution des `RegleAnnulation` (décision structurante n°2 du plan) : activité >
 * ressource > type_ressource > établissement — la plus spécifique l'emporte.
 */
final class ResolveurRegleAnnulationTest extends KernelTestCase
{
    public function testPrioriteActiviteRessourceTypeEtablissement(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        $container->get(SocleFixtures::class)->load($em);
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etab);

        $ressource = (new Ressource())->setEtablissement($etab)->setCodeType('terrain')->setLibelle('Terrain test')->setCapacitePropre(4);
        $em->persist($ressource);
        $activite = (new Activite())->setEtablissement($etab)->setLibelle('Activité test')->setTypeActivite('sport')->setDureeMinutes(60)->setTarifReferenceMontant('20.00');
        $em->persist($activite);

        $regleEtablissement = (new RegleAnnulation())->setEtablissement($etab)->setPortee(PorteeRegleAnnulation::Etablissement)
            ->setDelaiFrancMinutes(60)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('5.00')
            ->setModeFacturation(ModeFacturationNoShow::FactureAEncaisser)->setActif(true);
        $em->persist($regleEtablissement);

        $regleTypeRessource = (new RegleAnnulation())->setEtablissement($etab)->setPortee(PorteeRegleAnnulation::TypeRessource)
            ->setCibleTypeRessource('terrain')->setDelaiFrancMinutes(120)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('10.00')
            ->setModeFacturation(ModeFacturationNoShow::FactureAEncaisser)->setActif(true);
        $em->persist($regleTypeRessource);

        $regleRessource = (new RegleAnnulation())->setEtablissement($etab)->setPortee(PorteeRegleAnnulation::Ressource)
            ->setCibleRessource($ressource)->setDelaiFrancMinutes(180)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('15.00')
            ->setModeFacturation(ModeFacturationNoShow::FactureAEncaisser)->setActif(true);
        $em->persist($regleRessource);

        $regleActivite = (new RegleAnnulation())->setEtablissement($etab)->setPortee(PorteeRegleAnnulation::Activite)
            ->setCibleActivite($activite)->setDelaiFrancMinutes(1440)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant('20.00')
            ->setModeFacturation(ModeFacturationNoShow::FactureAEncaisser)->setActif(true);
        $em->persist($regleActivite);
        $em->flush();

        $creneau = (new Creneau())->setRessource($ressource)->setActivite($activite)
            ->setDebut(new \DateTimeImmutable('+1 day'))->setFin(new \DateTimeImmutable('+1 day +1 hour'))
            ->setCapacite(4)->setEtablissement($etab);
        $em->persist($creneau);
        $em->flush();

        $resolveur = new ResolveurRegleAnnulation($em);

        // Toutes les règles applicables : l'activité l'emporte.
        self::assertSame($regleActivite->getId()->toRfc4122(), $resolveur->resoudre($creneau)?->getId()->toRfc4122());

        // Sans règle activité : la ressource l'emporte.
        $em->remove($regleActivite);
        $em->flush();
        self::assertSame($regleRessource->getId()->toRfc4122(), $resolveur->resoudre($creneau)?->getId()->toRfc4122());

        // Sans règle ressource : le type de ressource l'emporte.
        $em->remove($regleRessource);
        $em->flush();
        self::assertSame($regleTypeRessource->getId()->toRfc4122(), $resolveur->resoudre($creneau)?->getId()->toRfc4122());

        // Sans règle type_ressource : l'établissement (la moins spécifique) s'applique en dernier recours.
        $em->remove($regleTypeRessource);
        $em->flush();
        self::assertSame($regleEtablissement->getId()->toRfc4122(), $resolveur->resoudre($creneau)?->getId()->toRfc4122());
    }
}
