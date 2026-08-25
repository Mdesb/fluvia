<?php

declare(strict_types=1);

namespace App\Tests\Musee\Unit;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Musee\DataFixtures\MuseeFixtures;
use App\Musee\Entity\AllocationQuotaOTA;
use App\Musee\Entity\ReservationOTA;
use App\Musee\Enum\StatutReservationOTA;
use App\Musee\Service\PrioriteOtaResolver;
use App\Offre\DataFixtures\OffreFixtures;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutReservation;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Priorité au 1ᵉʳ billet confirmé en cas de sur-vente OTA (US-MUSEE-08, décision actée, CA-8) —
 * unitaire, isolé de la couche HTTP (patron `App\Tests\Padel\Unit\CalculateurTarifTerrainHandlerTest`).
 */
final class PrioriteOtaResolverTest extends KernelTestCase
{
    public function testArbitrerTrieParHorodatageEtMarqueLesSuivantsRefuseeConflit(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, AccesFixtures::class, ReservationFixtures::class,
            MuseeFixtures::class,
        ] as $classe) {
            $container->get($classe)->load($em);
        }

        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);
        $creneau = $allocation->getCreneau();
        self::assertNotNull($creneau);
        $client = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $client);
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        self::assertInstanceOf(Beneficiaire::class, $beneficiaire);

        $quotaAvant = $allocation->getQuotaConsomme();

        $creerResaOta = static function (\DateTimeImmutable $horodatage) use ($em, $allocation, $creneau, $beneficiaire): ReservationOTA {
            $reservation = new Reservation();
            $reservation->setCreneau($creneau)->setOrganisateur($beneficiaire)->setEtablissement($allocation->getEtablissement())
                ->setModeDecompte(ModeDecompteReservation::VenteUnite)->setMontantDu('8.00')->setStatut(StatutReservation::Confirmee);
            $em->persist($reservation);

            $resaOta = (new ReservationOTA())->setAllocation($allocation)->setReservationRattachee($reservation)->setHorodatageConfirmation($horodatage);
            $em->persist($resaOta);

            $allocation->setQuotaConsomme($allocation->getQuotaConsomme() + 1);

            return $resaOta;
        };

        // Le second (plus récent) arrive en premier dans le tableau : l'arbitrage doit rétablir
        // la priorité au 1ᵉʳ confirmé (horodatage le plus ancien), indépendamment de l'ordre d'entrée.
        $tardif = $creerResaOta(new \DateTimeImmutable('+5 seconds'));
        $precoce = $creerResaOta(new \DateTimeImmutable('-5 seconds'));
        $em->flush();

        /** @var PrioriteOtaResolver $resolver */
        $resolver = $container->get(PrioriteOtaResolver::class);
        $resolver->arbitrer([$tardif, $precoce]);

        self::assertSame(StatutReservationOTA::Confirmee, $precoce->getStatutOta());
        self::assertSame(StatutReservationOTA::RefuseeConflit, $tardif->getStatutOta());
        self::assertSame(StatutReservation::AnnuleeLibre, $tardif->getReservationRattachee()?->getStatut(), 'Le billet perdant est annulé côté inventaire.');
        self::assertSame(StatutReservation::Confirmee, $precoce->getReservationRattachee()?->getStatut());

        $em->clear();
        $allocationApres = $em->getRepository(AllocationQuotaOTA::class)->find($allocation->getId());
        self::assertNotNull($allocationApres);
        // 2 ventes créées, 1 perdante -> quota net = avant + 1 (la gagnante seulement).
        self::assertSame($quotaAvant + 1, $allocationApres->getQuotaConsomme(), 'Le quota du perdant est restitué (CA-8).');
    }

    public function testMoinsDeDeuxCandidatsNeChangeRien(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var PrioriteOtaResolver $resolver */
        $resolver = $container->get(PrioriteOtaResolver::class);

        self::assertSame([], $resolver->arbitrer([]));
    }

    /**
     * L'arbitrage annule le billet perdant : il doit lui rendre sa seance de carte (CQ-3/CQ-6).
     *
     * **Ce que ce test protege.** Le perdant n-a rien fait de mal — son billet disparait par une
     * decision de la plateforme, pas par la sienne. Ne pas restituer le credit lui volerait une
     * seance definitivement et sans trace : le solde de sa carte serait juste plus bas, sans qu-aucun
     * ecran ne dise pourquoi. Verifie rouge sans la restitution avant d-etre declare vert.
     */
    public function testLArbitrageRendLaSeanceDeCarteAuBilletPerdant(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, AccesFixtures::class, ReservationFixtures::class,
            MuseeFixtures::class,
        ] as $classe) {
            $container->get($classe)->load($em);
        }

        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);
        $creneau = $allocation->getCreneau();
        self::assertNotNull($creneau);
        $clientCrm = $em->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $clientCrm);
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $clientCrm]);
        self::assertInstanceOf(Beneficiaire::class, $beneficiaire);

        // Une carte de N reservations deja debitee d-une seance par le billet perdant.
        $carte = (new DroitAcces())
            ->setSourceType(TypeDroitAcces::CarteQuota)
            ->setCreditRestant(0)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setFenetreDebut(new \DateTimeImmutable('-1 day'))
            ->setFenetreFin(new \DateTimeImmutable('+1 year'))
            ->setEtablissement($allocation->getEtablissement());
        $em->persist($carte);
        $em->flush();

        $creerResaOta = static function (\DateTimeImmutable $horodatage, ?DroitAcces $carteDebitee) use ($em, $allocation, $creneau, $beneficiaire): ReservationOTA {
            $reservation = new Reservation();
            $reservation->setCreneau($creneau)->setOrganisateur($beneficiaire)->setEtablissement($allocation->getEtablissement())
                ->setModeDecompte(ModeDecompteReservation::VenteUnite)->setMontantDu('8.00')->setStatut(StatutReservation::Confirmee);
            if ($carteDebitee instanceof DroitAcces) {
                $reservation->setCreditDroitRef($carteDebitee->getId());
            }
            $em->persist($reservation);

            $resaOta = (new ReservationOTA())->setAllocation($allocation)->setReservationRattachee($reservation)->setHorodatageConfirmation($horodatage);
            $em->persist($resaOta);

            return $resaOta;
        };

        $tardif = $creerResaOta(new \DateTimeImmutable('+5 seconds'), $carte);
        $precoce = $creerResaOta(new \DateTimeImmutable('-5 seconds'), null);
        $em->flush();
        $idCarte = $carte->getId();

        /** @var PrioriteOtaResolver $resolver */
        $resolver = $container->get(PrioriteOtaResolver::class);
        $resolver->arbitrer([$tardif, $precoce]);

        self::assertSame(StatutReservationOTA::RefuseeConflit, $tardif->getStatutOta(), 'Le tardif perd l-arbitrage.');

        $em->clear();
        $carteApres = $em->getRepository(DroitAcces::class)->find($idCarte);
        self::assertInstanceOf(DroitAcces::class, $carteApres);
        self::assertSame(1, $carteApres->getCreditRestant(), 'La seance revient au porteur : il n-a pas choisi de perdre sa place.');
    }
}
