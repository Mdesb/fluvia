<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Tests\Musee\MuseeApiTestCase;

/**
 * Visites guidées — guide qualifié (US-MUSEE-03, RG-MUS-02, CA-3) et bascule audioguide en cas
 * d'indisponibilité linguistique (US-MUSEE-04, décision actée, CA-4).
 */
final class VisiteGuideeTest extends MuseeApiTestCase
{
    public function testCa3VisiteConfirmeeAvecGuideQualifieCapacitePropreIndependanteJaugeEntree(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idGuide = $this->idGuide();
        $debut = (new \DateTimeImmutable('next thursday'))->setTime(10, 0);

        $client->request('POST', '/api/musee/visites-guidees', $entete + [
            'json' => [
                'theme' => 'Visite guidée de la salle des sarcophages',
                'langue' => 'fr',
                'pointRDV' => 'Accueil principal',
                'debut' => $debut->format(DATE_ATOM),
                'fin' => $debut->modify('+1 hour')->format(DATE_ATOM),
                'capacite' => 15,
                'guide' => '/api/musee_guides/' . $idGuide,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $visite = $client->getResponse()->toArray();
        self::assertSame('planifiee', $visite['statut']);
        $idVisite = basename((string) $visite['@id']);

        $client->request('POST', '/api/musee/visites-guidees/' . $idVisite . '/confirmer', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('confirmee', $client->getResponse()->toArray()['statut'], 'CA-3 : guide qualifié FR disponible -> confirmation acceptée.');
    }

    public function testCa3ConfirmationRefuseeSansGuideAffecte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $debut = (new \DateTimeImmutable('next thursday'))->setTime(11, 0);

        $client->request('POST', '/api/musee/visites-guidees', $entete + [
            'json' => [
                'theme' => 'Visite sans guide',
                'langue' => 'fr',
                'pointRDV' => 'Accueil principal',
                'debut' => $debut->format(DATE_ATOM),
                'fin' => $debut->modify('+1 hour')->format(DATE_ATOM),
                'capacite' => 15,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idVisite = basename((string) $client->getResponse()->toArray()['@id']);

        $client->request('POST', '/api/musee/visites-guidees/' . $idVisite . '/confirmer', $entete);
        self::assertResponseStatusCodeSame(422, 'RG-MUS-02 : confirmation refusée sans guide affecté.');
    }

    public function testCa4GuideNonQualifieDansLaLangueRefuseEtBasculeAudioguideProposee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idGuide = $this->idGuide();
        $debut = (new \DateTimeImmutable('next thursday'))->setTime(15, 0);

        // Le guide fixture n'est qualifié qu'en FR/EN : demande en langue non qualifiée (es).
        $client->request('POST', '/api/musee/visites-guidees', $entete + [
            'json' => [
                'theme' => 'Visita guiada en español',
                'langue' => 'es',
                'pointRDV' => 'Accueil principal',
                'debut' => $debut->format(DATE_ATOM),
                'fin' => $debut->modify('+1 hour')->format(DATE_ATOM),
                'capacite' => 15,
                'guide' => '/api/musee_guides/' . $idGuide,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $visite = $client->getResponse()->toArray();
        $idVisite = basename((string) $visite['@id']);

        $client->request('POST', '/api/musee/visites-guidees/' . $idVisite . '/confirmer', $entete);
        self::assertResponseStatusCodeSame(422, 'CA-4 : aucun guide qualifié dans la langue demandée -> refus explicite (pas de confirmation directe).');

        // Issue 1 : inscription en liste d'attente générique (réutilise le module Réservation).
        $client->request('POST', '/api/reservation/creneaux/' . basename((string) $visite['creneauVisite']) . '/liste-attente', $entete + [
            'json' => ['beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur()],
        ]);
        self::assertResponseIsSuccessful();

        // Issue 2 : bascule audioguide + remise automatique (CA-4/CA-11).
        $audioguide = $this->entite(\App\Musee\Entity\Audioguide::class, []);

        $client->request('POST', '/api/musee/bascules-audioguide', $entete + [
            'json' => [
                'visiteGuideeRefInitiale' => '/api/musee_visite_guidees/' . $idVisite,
                'audioguide' => '/api/musee_audioguides/' . (string) $audioguide->getId(),
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $bascule = $client->getResponse()->toArray();
        self::assertSame('20.00', $bascule['tauxRemise'], 'CA-4/CA-11 : taux de remise par défaut de l\'établissement appliqué automatiquement.');
    }

    public function testGuideDejaAffecteSurCreneauChevauchantRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idGuide = $this->idGuide();
        $debut = (new \DateTimeImmutable('next friday'))->setTime(10, 0);

        $client->request('POST', '/api/musee/visites-guidees', $entete + [
            'json' => [
                'theme' => 'Visite 1', 'langue' => 'fr', 'pointRDV' => 'Accueil',
                'debut' => $debut->format(DATE_ATOM), 'fin' => $debut->modify('+1 hour')->format(DATE_ATOM),
                'capacite' => 10, 'guide' => '/api/musee_guides/' . $idGuide,
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Chevauchement (10h30-11h30) sur le même guide.
        $client->request('POST', '/api/musee/visites-guidees', $entete + [
            'json' => [
                'theme' => 'Visite 2', 'langue' => 'fr', 'pointRDV' => 'Accueil',
                'debut' => $debut->modify('+30 minutes')->format(DATE_ATOM), 'fin' => $debut->modify('+90 minutes')->format(DATE_ATOM),
                'capacite' => 10, 'guide' => '/api/musee_guides/' . $idGuide,
            ],
        ]);
        self::assertResponseStatusCodeSame(409, 'RG-MUS-02 : un guide ne peut être affecté à deux visites chevauchantes.');
    }
}
