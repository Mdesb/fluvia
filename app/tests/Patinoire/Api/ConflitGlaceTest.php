<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\Tests\Patinoire\PatinoireApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Surbooking de la glace — tolérance manuelle (US-PATIN-09, RG-PAT-03, CA-9, décision actée). Le
 * moteur générique `App\Reservation` bloque déjà tout chevauchement à la création standard
 * (`ChevauchementCreneauGuard`, RG-M5-03) : les deux créneaux qui se chevauchent ici sont donc insérés
 * **directement en base** (hors API, patron « erreur de saisie / import / correction manuelle » évoqué
 * spec §4.8/§7) pour matérialiser le cas que `ConflitGlaceProvider` doit détecter — CA-9 exige
 * seulement qu'un chevauchement **existant** soit signalé **sans blocage à la lecture**, jamais que le
 * moteur socle laisse passer un chevauchement à l'écriture (hors périmètre patinoire, §4.8).
 */
final class ConflitGlaceTest extends PatinoireApiTestCase
{
    public function testChevauchementVisibleSansBlocage(): void
    {
        [$client, $entete, $idA] = $this->gestionnaireGlaceSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(Etablissement::class)->find($idA);
        self::assertNotNull($etablissement);

        $ressourceGlace = (new Ressource())->setEtablissement($etablissement)->setCodeType('glace')->setLibelle('Piste de glace démo')->setCapacitePropre(80);
        $em->persist($ressourceGlace);

        $debut = (new \DateTimeImmutable('next monday'))->setTime(14, 0);
        $creneauPublic = (new Creneau())->setRessource($ressourceGlace)->setDebut($debut)->setFin($debut->modify('+2 hours'))
            ->setCapacite(80)->setEtablissement($etablissement)->setStatut(StatutCreneau::Planifie)->setPublicReserve('public');
        $em->persist($creneauPublic);

        // Chevauche : hockey 15h-16h, alors que le public occupe 14h-16h (conflit réel sur la glace).
        $debutHockey = $debut->modify('+1 hour');
        $creneauHockey = (new Creneau())->setRessource($ressourceGlace)->setDebut($debutHockey)->setFin($debutHockey->modify('+1 hour'))
            ->setCapacite(20)->setEtablissement($etablissement)->setStatut(StatutCreneau::Planifie)->setPublicReserve('hockey');
        $em->persist($creneauHockey);

        // Un créneau non chevauchant, pour vérifier qu'il n'est pas remonté comme conflit.
        $debutSoir = $debut->modify('+5 hours');
        $creneauSoir = (new Creneau())->setRessource($ressourceGlace)->setDebut($debutSoir)->setFin($debutSoir->modify('+1 hour'))
            ->setCapacite(80)->setEtablissement($etablissement)->setStatut(StatutCreneau::Planifie)->setPublicReserve('public');
        $em->persist($creneauSoir);

        $em->flush();

        $client->request('GET', '/api/patinoire/conflits-glace', $entete);
        self::assertResponseIsSuccessful('CA-9 : aucun blocage à la lecture, la tolérance est actée (RG-PAT-03).');
        $vue = $client->getResponse()->toArray();

        self::assertCount(1, $vue['conflits'], 'CA-9 : exactement un chevauchement détecté (public/hockey), signalé sans blocage.');
        $idsEnConflit = [$vue['conflits'][0]['creneauA']['id'], $vue['conflits'][0]['creneauB']['id']];
        self::assertContains((string) $creneauPublic->getId(), $idsEnConflit);
        self::assertContains((string) $creneauHockey->getId(), $idsEnConflit);
        self::assertNotContains((string) $creneauSoir->getId(), $idsEnConflit);
    }

    public function testConsultationReserveeAuGestionnaireGlace(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $client->request('GET', '/api/patinoire/conflits-glace', $entete);
        self::assertResponseStatusCodeSame(403, 'patinoire.arbitrer_surbooking requis (agent de comptoir non habilité).');
    }
}
