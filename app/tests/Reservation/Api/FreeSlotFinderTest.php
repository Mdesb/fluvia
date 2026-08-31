<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Organisation\Entity\Etablissement;
use App\DataFixtures\SocleFixtures;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\DisponibiliteRessource;
use App\Reservation\Entity\IndisponibiliteRessource;
use App\Reservation\Entity\Ressource;
use App\Reservation\Service\FreeSlotFinder;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE PLACEMENT LIBRE — poser un rendez-vous là où il tient.
 *
 * **Pourquoi ces tests plutôt qu'un essai à l'écran.** Un calcul de disponibilité se trompe en
 * silence : il rend une liste d'heures plausibles, et rien ne distingue « il reste sept créneaux » de
 * « il en reste sept, mais pas les bons ». L'erreur ne se voit qu'au moment où deux clients se
 * présentent à la même heure — c'est-à-dire trop tard, et devant eux.
 *
 * > **Un agenda faux ne ressemble pas à un agenda cassé. Il ressemble à un agenda.**
 *
 * Chaque test fixe donc une situation minuscule et énonce la liste attendue **en toutes lettres**, pas
 * un décompte : un décompte passerait avec les mauvaises heures.
 */
final class FreeSlotFinderTest extends ReservationApiTestCase
{
    private const JOUR = '2026-09-07'; // un lundi

    /**
     * **Le cas qui justifie le battement.**
     *
     * Praticien ouvert de 9 h à 12 h. Un soin de 10 h à 10 h 30 est déjà pris, et la prestation
     * demande **quinze minutes de remise en état**. Un soin de trente minutes, proposé au quart
     * d'heure.
     *
     * Ce qu'on attend, et qui n'est évident qu'écrit :
     *
     * - **9 h 30 est proposé** — il finit à 10 h pile, au moment où l'autre commence. Un test qui
     *   refuserait l'égalité perdrait un créneau sur deux dans une journée bien remplie.
     * - **9 h 45 ne l'est pas** — il finirait à 10 h 15, en plein sur l'autre.
     * - **10 h 30 ne l'est pas non plus, et c'est tout l'objet du battement** : le rendez-vous est
     *   fini, la cabine ne l'est pas. Sans battement, on vendrait ce créneau et le client suivant
     *   attendrait debout.
     * - **10 h 45 est le premier possible après.**
     */
    public function testLeBattementRetireLeCreneauQuiCollleAuPrecedent(): void
    {
        [$ressource, $activite] = $this->poser(dureeMinutes: 30, battementMinutes: 15);
        $this->ouvrir($ressource, jour: 1, de: '09:00', a: '12:00');
        $this->occuper($ressource, $activite, de: '10:00', a: '10:30');

        self::assertSame(
            ['09:00', '09:15', '09:30', '10:45', '11:00', '11:15', '11:30'],
            $this->heures($ressource, $activite),
            'Le battement de la prestation deja posee doit repousser la proposition suivante.',
        );
    }

    /**
     * **Sans battement, le créneau collé redevient vendable.**
     *
     * Ce test existe pour que le précédent prouve quelque chose. Deux tests qui ne diffèrent que par
     * la valeur mesurée montrent que c'est bien elle qui décide — sinon le premier passerait aussi
     * avec un battement ignoré, pourvu qu'une autre règle retire par hasard le même créneau.
     */
    public function testSansBattementLeCreneauCollleAuPrecedentEstProposé(): void
    {
        [$ressource, $activite] = $this->poser(dureeMinutes: 30, battementMinutes: 0);
        $this->ouvrir($ressource, jour: 1, de: '09:00', a: '12:00');
        $this->occuper($ressource, $activite, de: '10:00', a: '10:30');

        self::assertContains(
            '10:30',
            $this->heures($ressource, $activite),
            'Sans battement, un rendez-vous peut commencer des la fin du precedent.',
        );
    }

    /**
     * **Une absence COUPE la journée, elle ne l'annule pas.**
     *
     * Un praticien absent de 12 h à 14 h sur une journée 9 h – 18 h reste disponible le matin et
     * l'après-midi. Traiter l'absence comme un filtre sur la journée effacerait tout : l'agenda
     * afficherait « complet » sur quelqu'un qui a six heures de libre, et personne ne saurait
     * pourquoi.
     */
    public function testUneAbsenceCoupeLaJourneeEnDeuxEtNeLaSupprimePas(): void
    {
        [$ressource, $activite] = $this->poser(dureeMinutes: 60, battementMinutes: 0);
        $this->ouvrir($ressource, jour: 1, de: '09:00', a: '18:00');
        $this->absenter($ressource, de: '12:00', a: '14:00');

        $heures = $this->heures($ressource, $activite);

        self::assertContains('11:00', $heures, 'Le matin reste ouvert jusqu a l absence.');
        self::assertNotContains('11:30', $heures, 'Un rendez-vous qui deborderait sur l absence est refuse.');
        self::assertNotContains('12:00', $heures, 'Pendant l absence, rien.');
        self::assertContains('14:00', $heures, 'L apres-midi rouvre a la fin de l absence.');
        self::assertContains('17:00', $heures, 'Le dernier creneau qui tient avant la fermeture est propose.');
        self::assertNotContains('17:30', $heures, 'Un rendez-vous qui deborderait sur la fermeture est refuse.');
    }

    /**
     * **Sans horaire déclaré, aucune proposition — et pas une journée entière ouverte.**
     *
     * C'est le défaut par défaut qui compte : une ressource sans `DisponibiliteRessource` ne doit pas
     * se retrouver ouverte de minuit à minuit. Le repli permissif est ici le seul irrattrapable — on
     * vendrait des rendez-vous à 4 h du matin, et le client se présenterait devant une porte fermée.
     */
    public function testSansHoraireDeclareRienNEstPropose(): void
    {
        [$ressource, $activite] = $this->poser(dureeMinutes: 30, battementMinutes: 0);

        self::assertSame([], $this->heures($ressource, $activite));
    }

    /** **Un autre jour de la semaine n'hérite pas des horaires du lundi.** */
    public function testLesHorairesSontPropresAUnJourDeLaSemaine(): void
    {
        [$ressource, $activite] = $this->poser(dureeMinutes: 30, battementMinutes: 0);
        $this->ouvrir($ressource, jour: 2, de: '09:00', a: '12:00'); // mardi

        self::assertSame([], $this->heures($ressource, $activite), 'Le lundi ne doit rien recevoir.');
    }

    // --- outillage ------------------------------------------------------------------------------

    /** @return list<string> heures de début, au format HH:MM */
    private function heures(Ressource $ressource, Activite $activite): array
    {
        /** @var FreeSlotFinder $finder */
        $finder = static::getContainer()->get(FreeSlotFinder::class);

        return array_map(
            static fn (array $creneau): string => $creneau['debut']->format('H:i'),
            $finder->findStarts($ressource, $activite, new \DateTimeImmutable(self::JOUR), 15),
        );
    }

    /** @return array{0: Ressource, 1: Activite} */
    private function poser(int $dureeMinutes, int $battementMinutes): array
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $ressource = (new Ressource())
            ->setEtablissement($etablissement)
            ->setCodeType('personnel')
            ->setLibelle('Praticien ' . bin2hex(random_bytes(3)))
            ->setCapacitePropre(1);
        $em->persist($ressource);

        $activite = (new Activite())
            ->setEtablissement($etablissement)
            ->setLibelle('Prestation ' . bin2hex(random_bytes(3)))
            ->setTypeActivite('soin')
            ->setDureeMinutes($dureeMinutes)
            ->setBattementMinutes($battementMinutes);
        $em->persist($activite);
        $em->flush();

        return [$ressource, $activite];
    }

    private function ouvrir(Ressource $ressource, int $jour, string $de, string $a): void
    {
        $em = $this->em();
        $em->persist(
            (new DisponibiliteRessource())
                ->setRessource($ressource)
                ->setJourSemaine($jour)
                ->setHeureDebut(new \DateTimeImmutable($de))
                ->setHeureFin(new \DateTimeImmutable($a))
        );
        $em->flush();
    }

    private function occuper(Ressource $ressource, Activite $activite, string $de, string $a): void
    {
        $em = $this->em();
        $em->persist(
            (new Creneau())
                // Le creneau porte son propre etablissement (colonne NOT NULL) : on le prend sur la
                // ressource plutot que de le redemander, pour que les trois entites du test soient
                // forcement sur le meme site.
                ->setEtablissement($ressource->getEtablissement())
                ->setRessource($ressource)
                ->setActivite($activite)
                ->setDebut(new \DateTimeImmutable(self::JOUR . ' ' . $de))
                ->setFin(new \DateTimeImmutable(self::JOUR . ' ' . $a))
                ->setCapacite(1)
        );
        $em->flush();
    }

    private function absenter(Ressource $ressource, string $de, string $a): void
    {
        $em = $this->em();
        $em->persist(
            (new IndisponibiliteRessource())
                ->setRessource($ressource)
                ->setDebut(new \DateTimeImmutable(self::JOUR . ' ' . $de))
                ->setFin(new \DateTimeImmutable(self::JOUR . ' ' . $a))
                ->setMotif('Déjeuner')
        );
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
