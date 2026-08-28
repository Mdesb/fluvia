<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Support\DataFixtures\SupportFixtures;
use App\Tests\Support\SupportApiTestCase;

/**
 * L'ASSISTANCE EST DU SOCLE — ce que peut un compte que personne n'a configuré pour elle.
 *
 * Maxime, le 28/08 : *« Le module d'assistance doit être mis par défaut pour tous les clients. »*
 * Ce n'était pas le cas : ouvrir un ticket exigeait `support.ouvrir_ticket`, lire la base de
 * connaissances exigeait `support.lire`, et rien ne les distribuait. Un client ouvert la veille
 * n'avait donc aucun compte capable de demander de l'aide — exactement les comptes qui en ont le
 * plus besoin, puisque personne ne les avait encore configurés.
 *
 * ⚠ **Le sujet de ces tests est `EMAIL_SANS_ROLE_SUPPORT`, et cela n'est pas un détail.** Son rôle
 * ne porte que `caisse.lire`. Écrits sur l'un des neuf autres comptes de `SupportFixtures`, ces
 * tests resteraient verts si l'on retirait le socle demain — ils mesureraient le rôle, pas la règle.
 *
 * Trois droits, et strictement trois : lire la base, ouvrir un ticket, suivre les siens. Le
 * troisième test vérifie la borne — ce que le socle n'accorde PAS. Sans lui, on n'aurait pas
 * distingué « l'assistance est ouverte à tous » de « tout le monde est agent de support ».
 */
final class AssistanceDeSocleTest extends SupportApiTestCase
{
    public function testUnCompteSansRoleDAssistanceOuvreEtSuitSonTicket(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_SANS_ROLE_SUPPORT, SupportFixtures::ETAB_A_NOM);

        $ticket = $client->request('POST', '/api/support/tickets', $entete + [
            'json' => [
                'sujet' => 'Le lecteur de badge de l’entrée ne répond plus',
                'description' => "Aucun bip depuis l’ouverture ; les adhérents entrent à la main.",
                'priorite' => 'haute',
                'moduleConcerne' => 'acces',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('nouveau', $ticket['statut']);

        // Ouvrir sans pouvoir relire serait une boîte aux lettres sans fente de retour : le
        // demandeur ne verrait jamais la réponse. `support.lire_ticket_soi` fait partie du socle
        // pour cette raison, et ce second appel est ce qui le prouve.
        $relu = $client->request('GET', '/api/support/tickets/' . $ticket['id'], $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($ticket['id'], $relu['id']);
    }

    public function testUnCompteSansRoleDAssistanceLitLaBaseDeConnaissance(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_SANS_ROLE_SUPPORT, SupportFixtures::ETAB_A_NOM);

        $client->request('GET', '/api/article_aides', $entete);
        self::assertResponseIsSuccessful();
    }

    /**
     * LA BORNE. Ouvrir l'assistance à tous n'est pas faire de tout le monde un agent de support.
     */
    public function testLeSocleNAccordePasLesGestesDAgentNiDeRedacteur(): void
    {
        [$demandeur, $enteteDemandeur] = $this->connecte(SupportFixtures::EMAIL_SANS_ROLE_SUPPORT, SupportFixtures::ETAB_A_NOM);

        $ticket = $demandeur->request('POST', '/api/support/tickets', $enteteDemandeur + [
            'json' => [
                'sujet' => 'Ticket de contrôle',
                'description' => 'Ouvert pour vérifier ce que le socle ne donne pas.',
                'priorite' => 'normale',
                'moduleConcerne' => 'socle',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $demandeur->request('POST', '/api/support/tickets/' . $ticket['id'] . '/prendre-en-charge', $enteteDemandeur);
        self::assertResponseStatusCodeSame(403);

        $demandeur->request('POST', '/api/article_aides', $enteteDemandeur + [
            'json' => [
                'titre' => 'Article que ce compte ne doit pas pouvoir écrire',
                'contenuMarkdown' => '## Rien',
                'publicCible' => 'tous',
                'portee' => 'global',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
