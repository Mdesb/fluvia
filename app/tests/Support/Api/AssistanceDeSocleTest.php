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

    /**
     * QUI PARLE — le nom voyage avec le ticket et avec le message.
     *
     * `MessageTicket.auteur` et `TicketSupport.demandeur` sont des relations : sans groupe de
     * lecture commun, API Platform les serialise en IRI (`/api/utilisateurs/<uuid>`). L'écran
     * recevait donc une URL là où il attendait un nom, et affichait « Auteur inconnu » sur chaque
     * bulle de l'interlocuteur — un défaut qui préexistait à la messagerie, mais qu'un tableau
     * rendait invisible et qu'une conversation écrit vingt fois.
     *
     * ⚠ Ce test vérifie aussi CE QUI N'EST PAS EXPOSÉ. Le nom suffit à lire une conversation ;
     * l'adresse e-mail n'y ajoute rien et la file d'un agent N2 traverse plusieurs établissements.
     * Sans cette seconde moitié, personne ne remarquerait qu'un `utilisateur:read` ajouté un jour
     * par commodité fait voyager les adresses avec les tickets.
     */
    public function testLeNomDeLAuteurVoyageAvecLeTicketEtLeMessage(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_SANS_ROLE_SUPPORT, SupportFixtures::ETAB_A_NOM);

        $ticket = $client->request('POST', '/api/support/tickets', $entete + [
            'json' => [
                'sujet' => 'Qui parle ?',
                'description' => 'Le fil doit savoir nommer celui qui écrit.',
                'priorite' => 'normale',
                'moduleConcerne' => 'socle',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        // Le demandeur du ticket est un OBJET nommé, pas une IRI.
        self::assertIsArray($ticket['demandeur'], 'Le demandeur est sérialisé en IRI : l’écran ne peut pas le nommer.');
        self::assertSame('Compte ordinaire', $ticket['demandeur']['nom']);
        self::assertArrayHasKey('id', $ticket['demandeur']);
        self::assertArrayNotHasKey('email', $ticket['demandeur']);

        $client->request('POST', '/api/support/tickets/' . $ticket['id'] . '/messages', $entete + [
            'json' => ['contenu' => 'Un complément d’information.', 'noteInterne' => false],
        ]);
        self::assertResponseIsSuccessful();

        $messages = $client->request('GET', '/api/support/tickets/' . $ticket['id'] . '/messages', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($messages['member'], 'Sans message, les assertions suivantes seraient vraies sans rien prouver.');

        $auteur = $messages['member'][0]['auteur'];
        self::assertIsArray($auteur, 'L’auteur du message est sérialisé en IRI : la bulle affichera « Auteur inconnu ».');
        self::assertSame('Compte ordinaire', $auteur['nom']);
        // L'identifiant est ce qui donne un CÔTÉ à la bulle : sans lui, tout s'aligne à gauche.
        self::assertArrayHasKey('id', $auteur);
        self::assertArrayNotHasKey('email', $auteur);
    }

    /**
     * OUVRIR L'ASSISTANCE À TOUS N'OUVRE PAS LA FILE À TOUS.
     *
     * La question a été posée en revue : `support.lire` accordé à tout le monde donne-t-il la file
     * complète à un agent d'accueil ? Le code dit non — `TicketSupport::GetCollection` n'accepte pas
     * `support.lire`, et `PerimetreSupportExtension` retombe sur `demandeur = utilisateur courant`
     * faute d'une permission plus large. Mais lire le code n'est pas voir le filet attraper : un
     * élargissement de droits par défaut se vérifie, il ne se raisonne pas.
     */
    public function testLeSocleNeMontreQueSesPropresDemandes(): void
    {
        [$autre, $enteteAutre] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);
        $ticketDUnAutre = $autre->request('POST', '/api/support/tickets', $enteteAutre + [
            'json' => [
                'sujet' => 'Demande d’un autre exploitant',
                'description' => 'Ce compte ordinaire ne doit jamais voir cette demande.',
                'priorite' => 'normale',
                'moduleConcerne' => 'vente',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        [$ordinaire, $enteteOrdinaire] = $this->connecte(SupportFixtures::EMAIL_SANS_ROLE_SUPPORT, SupportFixtures::ETAB_A_NOM);

        // ⚠ LA GARDE QUI REND L'ASSERTION SUIVANTE SIGNIFIANTE. Un 403 sur la collection rendrait
        // « ne contient pas le ticket de l'autre » vrai sans rien prouver — la réponse ne
        // contiendrait rien du tout. On exige donc que l'appel ABOUTISSE avant de compter.
        $liste = $ordinaire->request('GET', '/api/support/tickets', $enteteOrdinaire)->toArray();
        self::assertResponseIsSuccessful();

        $ids = array_map(static fn (array $t): string => $t['id'], $liste['member'] ?? []);
        self::assertNotContains($ticketDUnAutre['id'], $ids);

        // Et pas davantage en visant l'identifiant directement : la collection filtrée ne vaut rien
        // si l'accès direct passe. C'est la forme que prend une IDOR quand on ne la cherche pas.
        $ordinaire->request('GET', '/api/support/tickets/' . $ticketDUnAutre['id'], $enteteOrdinaire);
        self::assertResponseStatusCodeSame(404);
    }
}
