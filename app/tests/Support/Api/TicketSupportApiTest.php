<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Support\DataFixtures\SupportFixtures;
use App\Tests\Support\SupportApiTestCase;

/**
 * Tickets de support (US-SUP-09 à 14, RG-SUP-09 à 15) : ouverture (CA-8), cycle de vie (CA-9),
 * escalade N1→N2 (CA-10), note interne (CA-11), lien article (CA-12), tableau de bord (CA-13),
 * audit (CA-14), cloisonnement établissement (RG-SOCLE-05).
 */
final class TicketSupportApiTest extends SupportApiTestCase
{
    public function testCa8OuvertureTicketParExploitantAuthentifie(): void
    {
        [$client, $entete] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);

        $ticket = $client->request('POST', '/api/support/tickets', $entete + [
            'json' => [
                'sujet' => 'Impossible de clôturer la caisse',
                'description' => "La clôture Z échoue depuis ce matin.",
                'priorite' => 'haute',
                'moduleConcerne' => 'vente',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('nouveau', $ticket['statut']);
        self::assertSame($this->idEtablissement(SupportFixtures::ETAB_A_NOM), basename($ticket['etablissement']));
    }

    public function testCa9CycleDeVieTicket(): void
    {
        [$clientDemandeur, $enteteDemandeur] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);
        $ticket = $this->ouvrirTicket($clientDemandeur, $enteteDemandeur);

        [$clientN1, $enteteN1] = $this->connecte(SupportFixtures::EMAIL_AGENT_N1, SupportFixtures::ETAB_A_NOM);

        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/prendre-en-charge', $enteteN1);
        self::assertResponseIsSuccessful();
        $apresPriseEnCharge = $clientN1->request('GET', '/api/support/tickets/' . $ticket['id'], $enteteN1)->toArray();
        self::assertSame('en_cours', $apresPriseEnCharge['statut']);

        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/statut', $enteteN1 + [
            'json' => ['statut' => 'resolu'],
        ]);
        self::assertResponseIsSuccessful();
        $apresResolution = $clientN1->request('GET', '/api/support/tickets/' . $ticket['id'], $enteteN1)->toArray();
        self::assertSame('resolu', $apresResolution['statut']);
        self::assertNotNull($apresResolution['dateResolution']);

        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/statut', $enteteN1 + [
            'json' => ['statut' => 'ferme', 'motifFermeture' => 'Résolu par le demandeur.'],
        ]);
        self::assertResponseIsSuccessful();
        $apresFermeture = $clientN1->request('GET', '/api/support/tickets/' . $ticket['id'], $enteteN1)->toArray();
        self::assertSame('ferme', $apresFermeture['statut']);
        self::assertNotNull($apresFermeture['dateFermeture']);
    }

    public function testCa10EscaladeN1VersN2Tracee(): void
    {
        [$clientDemandeur, $enteteDemandeur] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);
        $ticket = $this->ouvrirTicket($clientDemandeur, $enteteDemandeur);

        [$clientN1, $enteteN1] = $this->connecte(SupportFixtures::EMAIL_AGENT_N1, SupportFixtures::ETAB_A_NOM);
        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/prendre-en-charge', $enteteN1);
        self::assertResponseIsSuccessful();

        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/escalader', $enteteN1 + ['json' => []]);
        self::assertResponseIsSuccessful();

        $apres = $clientN1->request('GET', '/api/support/tickets/' . $ticket['id'], $enteteN1)->toArray();
        self::assertSame('N2', $apres['niveauAffectation']);

        // Traçabilité (RG-SUP-15) : une EntreeAudit existe pour ce TicketSupport.
        $entree = $this->entite(\App\Audit\Entity\EntreeAudit::class, ['cibleId' => $ticket['id']]);
        self::assertNotNull($entree);
    }

    public function testCa11NoteInterneJamaisVisibleDuDemandeurEtDemandeurNePeutPasEnPoser(): void
    {
        [$clientDemandeur, $enteteDemandeur] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);
        $ticket = $this->ouvrirTicket($clientDemandeur, $enteteDemandeur);

        [$clientN1, $enteteN1] = $this->connecte(SupportFixtures::EMAIL_AGENT_N1, SupportFixtures::ETAB_A_NOM);
        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/prendre-en-charge', $enteteN1);

        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/messages', $enteteN1 + [
            'json' => ['contenu' => 'Note interne : vérifier le firmware ITBOX.', 'noteInterne' => true],
        ]);
        self::assertResponseIsSuccessful();

        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/messages', $enteteN1 + [
            'json' => ['contenu' => 'Bonjour, nous investiguons le problème.', 'noteInterne' => false],
        ]);
        self::assertResponseIsSuccessful();

        // Le demandeur ne voit jamais la note interne.
        $messagesDemandeur = $clientDemandeur->request('GET', '/api/support/tickets/' . $ticket['id'] . '/messages', $enteteDemandeur)->toArray();
        $contenusDemandeur = array_column($this->extraireHydraMembers($messagesDemandeur), 'contenu');
        self::assertNotContains('Note interne : vérifier le firmware ITBOX.', $contenusDemandeur);
        self::assertContains('Bonjour, nous investiguons le problème.', $contenusDemandeur);

        // L'agent voit tout.
        $messagesAgent = $clientN1->request('GET', '/api/support/tickets/' . $ticket['id'] . '/messages', $enteteN1)->toArray();
        self::assertCount(2, $this->extraireHydraMembers($messagesAgent));

        // Un demandeur ne peut pas poser noteInterne=true sur son propre message.
        $clientDemandeur->request('POST', '/api/support/tickets/' . $ticket['id'] . '/messages', $enteteDemandeur + [
            'json' => ['contenu' => 'Tentative de note interne côté demandeur.', 'noteInterne' => true],
        ]);
        self::assertResponseIsSuccessful();
        $messagesApres = $clientN1->request('GET', '/api/support/tickets/' . $ticket['id'] . '/messages', $enteteN1)->toArray();
        $listeMessagesApres = $this->extraireHydraMembers($messagesApres);

        // On retrouve le message par son contenu, pas par sa position. Les trois messages du ticket
        // naissent dans la meme seconde : le tri par `dateCreation` est alors ambigu, et `end()`
        // rendait tantot le message du demandeur, tantot la note interne de l'agent — d'ou un echec
        // intermittent, visible seulement en suite complete et jamais en execution isolee.
        //
        // Et ce n'est pas qu'une question de stabilite : l'assertion porte desormais sur ce que le
        // test veut reellement prouver — que **ce message-la** n'a pas ete accepte comme note interne
        // — au lieu de dependre de l'ordre de la liste.
        $messageDuDemandeur = null;
        foreach ($listeMessagesApres as $message) {
            if (($message['contenu'] ?? null) === 'Tentative de note interne côté demandeur.') {
                $messageDuDemandeur = $message;
                break;
            }
        }

        self::assertNotNull($messageDuDemandeur, 'Le message du demandeur est absent de la liste.');
        self::assertFalse($messageDuDemandeur['noteInterne']);
    }

    public function testCa12LienArticleVisibleDuDemandeur(): void
    {
        [$clientRedacteur, $enteteRedacteur] = $this->connecte(SupportFixtures::EMAIL_REDACTEUR_GLOBAL, SupportFixtures::ETAB_A_NOM);
        $categorie = $clientRedacteur->request('POST', '/api/categorie_aides', $enteteRedacteur + ['json' => ['nom' => 'Lien article ' . uniqid()]])->toArray();
        $article = $clientRedacteur->request('POST', '/api/article_aides', $enteteRedacteur + [
            'json' => [
                'titre' => 'Procédure de résolution',
                'categorie' => '/api/categorie_aides/' . $categorie['id'],
                'contenu' => 'Contenu.',
                'portee' => 'global',
                'publicCible' => 'agent',
            ],
        ])->toArray();

        [$clientDemandeur, $enteteDemandeur] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);
        $ticket = $this->ouvrirTicket($clientDemandeur, $enteteDemandeur);

        [$clientN1, $enteteN1] = $this->connecte(SupportFixtures::EMAIL_AGENT_N1, SupportFixtures::ETAB_A_NOM);
        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/prendre-en-charge', $enteteN1);

        $clientN1->request('POST', '/api/support/tickets/' . $ticket['id'] . '/lier-article', $enteteN1 + [
            'json' => ['articleId' => '/api/article_aides/' . $article['id']],
        ]);
        self::assertResponseIsSuccessful();

        $ticketDemandeur = $clientDemandeur->request('GET', '/api/support/tickets/' . $ticket['id'], $enteteDemandeur)->toArray();
        self::assertCount(1, $ticketDemandeur['articlesLies']);
    }

    public function testCa13TableauDeBordFiltreStatutNouveauEtNiveauN1IncluTicketsSansAgent(): void
    {
        [$clientDemandeur, $enteteDemandeur] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);
        $ticket = $this->ouvrirTicket($clientDemandeur, $enteteDemandeur);

        [$clientN1, $enteteN1] = $this->connecte(SupportFixtures::EMAIL_AGENT_N1, SupportFixtures::ETAB_A_NOM);

        $tableau = $clientN1->request('GET', '/api/support/tickets/tableau-de-bord?statut=nouveau', $enteteN1)->toArray();
        $membres = $this->extraireHydraMembers($tableau);
        $ids = array_column($membres, 'id');
        self::assertContains($ticket['id'], $ids);
    }

    public function testCloisonnementDemandeurNeVoitJamaisTicketsAutreEtablissement(): void
    {
        [$clientDemandeurA, $enteteDemandeurA] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_A, SupportFixtures::ETAB_A_NOM);
        $this->ouvrirTicket($clientDemandeurA, $enteteDemandeurA);

        [$clientDemandeurB, $enteteDemandeurB] = $this->connecte(SupportFixtures::EMAIL_EXPLOITANT_B, SupportFixtures::ETAB_B_NOM);
        $collectionB = $clientDemandeurB->request('GET', '/api/support/tickets', $enteteDemandeurB)->toArray();
        self::assertSame([], $this->extraireHydraMembers($collectionB), 'Un demandeur B ne voit aucun ticket du demandeur A.');
    }

    /** @return array<string, mixed> */
    private function ouvrirTicket(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): array
    {
        $reponse = $client->request('POST', '/api/support/tickets', $entete + [
            'json' => ['sujet' => 'Sujet de test', 'description' => 'Description de test.', 'priorite' => 'normale'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $reponse;
    }

    /** @return list<array<string, mixed>> */
    private function extraireHydraMembers(array $collection): array
    {
        return $collection['member'] ?? $collection['hydra:member'] ?? [];
    }
}
