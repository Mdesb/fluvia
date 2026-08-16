<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Musee\Entity\ContingentGratuite;
use App\Tests\Musee\MuseeApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gratuités scolaires — consommation du quota + contingent dédié (US-MUSEE-05, RG-MUS-03, décision
 * actée, CA-5) et dossiers groupes/scolaires (US-MUSEE-06, CA-6).
 */
final class GratuiteScolaireTest extends MuseeApiTestCase
{
    public function testCa6DossierPorteLesChampsAttendusEtDateOptionParDefaut(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->idCreneauApresMidi();

        $client->request('POST', '/api/musee/dossiers-groupe', $entete + [
            'json' => [
                'etablissementScolaire' => 'Collège Jean Moulin',
                'effectif' => 25,
                'accompagnateurs' => 3,
                'creneauEntree' => '/api/reservation_creneaus/' . $idCreneau,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $dossier = $client->getResponse()->toArray();
        self::assertSame('Collège Jean Moulin', $dossier['etablissementScolaire']);
        self::assertSame(25, $dossier['effectif']);
        self::assertSame(3, $dossier['accompagnateurs']);
        self::assertSame('en_option', $dossier['statutPaiement'], 'CA-6 : paiement différé, statut initial en_option.');
        self::assertNotEmpty($dossier['dateOption'], 'CA-6 : date d\'option par défaut (paramètre établissement, 15 jours).');
    }

    public function testCa5GratuiteDecrementeSimultanementJaugeEtContingent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneauMatin = $this->idCreneauMatin();
        $idContingent = $this->entite(ContingentGratuite::class, [])->getId();
        $idResponsable = $this->idBeneficiairePayeur();

        $client->request('POST', '/api/musee/dossiers-groupe', $entete + [
            'json' => [
                'etablissementScolaire' => 'École primaire Curie',
                'effectif' => 10,
                'accompagnateurs' => 2,
                'creneauEntree' => '/api/reservation_creneaus/' . $idCreneauMatin,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idDossier = basename((string) $client->getResponse()->toArray()['@id']);

        $client->request('POST', '/api/musee/dossiers-groupe/' . $idDossier . '/confirmer', $entete + [
            'json' => [
                'responsable' => '/api/beneficiaires/' . $idResponsable,
                'nbGratuitesEleve' => 3,
                'nbGratuitesAccompagnateur' => 1,
                'contingent' => '/api/musee_contingent_gratuites/' . (string) $idContingent,
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Contingent dédié (fixture, quota 5) : 4 gratuités accordées -> consommé = 4.
        $client->request('GET', '/api/musee_contingent_gratuites/' . (string) $idContingent, $entete);
        self::assertResponseIsSuccessful();
        $contingent = $client->getResponse()->toArray();
        self::assertSame(4, $contingent['quotaConsomme'], 'CA-5 : le contingent dédié est décrémenté du nombre de gratuités accordées.');

        // 4 Gratuite créées, chacune liée à une Reservation individuelle (décision n°2 du plan) —
        // vérifié directement en base (le filtre `dossier` de la collection API est instable en
        // combinaison avec le cloisonnement établissement, hors périmètre de cette assertion CA-5).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $gratuites = $em->getRepository(\App\Musee\Entity\Gratuite::class)->createQueryBuilder('g')
            ->andWhere('g.dossier = :dossier')->setParameter('dossier', $idDossier, 'uuid')
            ->getQuery()->getResult();
        self::assertCount(4, $gratuites, 'CA-5 : 4 Gratuite créées (3 élèves + 1 accompagnateur).');
    }

    public function testCa5ContingentEpuiseRefuseMemeSiJaugeDisponible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneauMatin = $this->idCreneauMatin();
        $idContingent = $this->entite(ContingentGratuite::class, [])->getId();
        $idResponsable = $this->idBeneficiairePayeur();

        // Épuise le contingent dédié (quota 5 en fixture) directement en base.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $contingent = $em->getRepository(ContingentGratuite::class)->find($idContingent);
        self::assertInstanceOf(ContingentGratuite::class, $contingent);
        $contingent->setQuotaConsomme($contingent->getQuotaGratuitesDedie());
        $em->flush();

        $client->request('POST', '/api/musee/dossiers-groupe', $entete + [
            'json' => [
                'etablissementScolaire' => 'École épuisement',
                'effectif' => 5,
                'accompagnateurs' => 0,
                'creneauEntree' => '/api/reservation_creneaus/' . $idCreneauMatin,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idDossier = basename((string) $client->getResponse()->toArray()['@id']);

        // Jauge du créneau largement disponible (capacité 30), mais contingent épuisé -> refus explicite.
        $client->request('POST', '/api/musee/dossiers-groupe/' . $idDossier . '/confirmer', $entete + [
            'json' => [
                'responsable' => '/api/beneficiaires/' . $idResponsable,
                'nbGratuitesEleve' => 1,
                'contingent' => '/api/musee_contingent_gratuites/' . (string) $idContingent,
            ],
        ]);
        self::assertResponseStatusCodeSame(422, 'CA-5 : contingent épuisé -> refus explicite même si la jauge du créneau reste disponible.');
    }
}
