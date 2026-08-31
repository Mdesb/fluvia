<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Tests\Compta\ComptaApiTestCase;

/**
 * US-L4-08, RG-M6-07/08/09 (CA-12) : agrégat e-reporting unique par SIREN, ventilé jour × taux TVA,
 * excluant les ventes marquées « ImpayeRegie » (anti-double-comptabilisation).
 */
final class EReportingTest extends ComptaApiTestCase
{
    public function testAgregatExcluLesVentesMarqueesImpayeeRegie(): void
    {
        [$client, $entete] = $this->adminSurA();

        $session = $this->ouvrirSession($client, $entete);
        $venteIncluse = $this->creerVenteValidee($client, $entete, quantite: 1, sessionId: $session['id']);
        $venteExclue = $this->creerVenteValidee($client, $entete, quantite: 1, sessionId: $session['id']);

        $client->request('POST', '/api/compta/ventes/' . $venteExclue['id'] . '/marquer-impayee-regie', $entete + [
            'json' => ['motif' => 'Recette déjà régularisée via la régie'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        $debut = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $fin = (new \DateTimeImmutable('last day of this month'))->format('Y-m-d');

        $declaration = $client->request('POST', '/api/compta/e-reporting', $entete + [
            'json' => [
                'profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'periodeDebut' => $debut,
                'periodeFin' => $fin,
            ],
        ])->toArray();

        self::assertSame('prepare', $declaration['statutEnvoi']);
        self::assertNotEmpty($declaration['siren']);
        self::assertNotEmpty($declaration['agregatParJourTaux']);

        $totalBaseHT = array_sum(array_column($declaration['agregatParJourTaux'], 'baseHTCentimes'));
        // Une seule vente (entrée unitaire, 20 %) doit alimenter l'agrégat, l'autre étant exclue.
        $ttcInclusCentimes = (int) round(((float) $venteIncluse['total']) * 100);
        $tvaAttendue = (int) round($ttcInclusCentimes * 20 / 120);
        $htAttendu = $ttcInclusCentimes - $tvaAttendue;
        self::assertSame($htAttendu, $totalBaseHT, 'La vente marquée ImpayeRegie doit être exclue de l\'agrégat (RG-M6-09).');

        // ⚠ CETTE ASSERTION DISAIT « transmis », ET ELLE ETAIT VRAIE SANS VALEUR.
        //
        // `PdpStubAdapter` rendait `Transmis` sans rien transmettre. Une declaration d'e-reporting
        // marquee transmise mais jamais deposee est une obligation declarative que plus personne ne
        // sait manquante. Arbitre par Maxime le 31/08 : un canal non raccorde refuse.
        $client->request('POST', '/api/compta/e-reporting/' . $declaration['id'] . '/transmettre', $entete);
        self::assertResponseStatusCodeSame(503, 'Aucune PDP n\'est raccordee : le depot ne peut pas reussir.');

        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('PAS été transmise', $corps, 'Le message doit dire ce qui n\'a PAS eu lieu.');

        // Et la declaration garde son statut : elle reste transmissible le jour du raccordement.
        // La declaration se relit sur sa route standard : `/api/compta/e-reporting` ne porte que
        // le POST de creation et la transmission. Verifie sur le routeur, pas suppose.
        $apres = $client->request('GET', '/api/declaration_e_reportings/' . $declaration['id'], $entete)->toArray();
        self::assertSame('prepare', $apres['statutEnvoi'], 'Un depot refuse ne degrade pas la declaration.');
    }
}
