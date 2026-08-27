<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Tests\Crm\CrmApiTestCase;

/**
 * UNE RELANCE NE SE COCHE PAS : ELLE SE REMPLACE EN AGISSANT.
 *
 * C'est **la** thèse du module commercial, et donc ce qu'il faut prouver. Le reste — enregistrer un
 * appel, lire une fiche — n'est que du rangement.
 *
 * Le module `Support` traite des demandes **subies**, qui se ferment. Ici, les échanges sont
 * **décidés** et la relation continue : il n'y a rien à fermer. On a donc refusé d'introduire un objet
 * « tâche commerciale » avec sa case à cocher, parce qu'une liste de tâches qu'il faut penser à vider
 * ne se vide jamais — et parce qu'elle aurait ouvert **une seconde boîte de travail** à côté de
 * `Project`, dont personne ne regarde les deux.
 *
 * À la place, l'état « relance en attente » est **déduit** : elle tient tant qu'aucune activité plus
 * récente n'existe sur la même cible. Rappeler, c'est enregistrer l'appel — et l'ancienne relance
 * disparaît d'elle-même.
 */
final class RelanceTest extends CrmApiTestCase
{
    /**
     * **Le mécanisme central, vu par son effet.**
     *
     * Une relance est posée, puis quelqu'un rappelle : l'appel remplace la relance sans que personne
     * n'ait rien coché. Si ce test tombait, le module rendrait éternellement des relances déjà
     * honorées — c'est-à-dire la panne qu'on a voulu éviter en n'écrivant pas de case à cocher.
     */
    public function testRappelerFaitDisparaitreLaRelanceSansRienCocher(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeur = $this->idPayeur();

        $this->activite($client, $entete, $payeur, 'Premier contact, interesse par un abonnement.', [
            'occurredAt' => '2026-08-10T10:00:00+00:00',
            'nextActionAt' => '2026-08-20',
            'nextAction' => 'Rappeler pour confirmer la formule annuelle',
        ]);

        self::assertSame(
            ['Rappeler pour confirmer la formule annuelle'],
            $this->relances($client, $entete),
            'Une relance posee doit etre en attente tant que personne n a rappele.',
        );

        // On rappelle. Aucune case cochee, aucun PATCH sur l'activite precedente : on enregistre
        // simplement ce qui vient de se passer.
        $this->activite($client, $entete, $payeur, 'Rappele : il reflechit, pas de suite pour l instant.', [
            'occurredAt' => '2026-08-21T09:00:00+00:00',
        ]);

        self::assertSame(
            [],
            $this->relances($client, $entete),
            'Le nouvel echange remplace la relance : elle ne doit plus etre en attente.',
        );
    }

    /**
     * **Rappeler ne suffit pas à clore : le nouvel échange peut porter la relance suivante.**
     *
     * C'est le versant qui empêche la règle de tout effacer. Sans lui, une implémentation qui se
     * contenterait de rendre la liste vide dès qu'il existe deux activités passerait le test
     * précédent.
     */
    public function testLeNouvelEchangePorteSaPropreRelance(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeur = $this->idPayeur();

        $this->activite($client, $entete, $payeur, 'Premier appel.', [
            'occurredAt' => '2026-08-10T10:00:00+00:00',
            'nextActionAt' => '2026-08-20',
            'nextAction' => 'Rappeler',
        ]);
        $this->activite($client, $entete, $payeur, 'Rappele : il veut un devis.', [
            'occurredAt' => '2026-08-21T09:00:00+00:00',
            'nextActionAt' => '2026-08-28',
            'nextAction' => 'Envoyer le devis annuel',
        ]);

        self::assertSame(
            ['Envoyer le devis annuel'],
            $this->relances($client, $entete),
            'La relance en attente est celle du DERNIER echange, et elle est seule.',
        );
    }

    /**
     * **Le retard se calcule, il ne se stocke pas.**
     *
     * Un drapeau « en retard » en base serait faux entre deux passages d'un traitement de nuit : la
     * relance du 20 resterait « à l'heure » le 21 au matin, exactement le jour où elle compte.
     */
    public function testLeRetardEstCalculeParRapportAAujourdhui(): void
    {
        [$client, $entete] = $this->adminSurA();

        $hier = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $this->activite($client, $entete, $this->idPayeur(), 'Echange ancien.', [
            'occurredAt' => '2026-01-05T10:00:00+00:00',
            'nextActionAt' => $hier,
            'nextAction' => 'Relance oubliee',
        ]);

        $charge = $client->request('GET', '/api/crm/relances', $entete)->toArray();
        self::assertSame(1, $charge['enRetard'], 'Une echeance depassee doit etre comptee en retard.');
        self::assertTrue($charge['relances'][0]['enRetard']);
    }

    /**
     * **Une date de relance sans intitulé est refusée.**
     *
     * Six semaines plus tard, « rappeler le 12 » ne dit ni pourquoi ni de quoi parler : l'appel ne se
     * fait pas, et la ligne encombre la liste jusqu'à ce qu'on la supprime sans savoir ce qu'elle
     * valait.
     */
    public function testUneDateDeRelanceSansIntituleEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/commercial_activities', $entete + [
            'json' => [
                'customer' => '/api/clients/' . $this->idPayeur(),
                'type' => 'call',
                'summary' => 'Appel sans suite precisee.',
                'nextActionAt' => '2026-09-15',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **Une activité accrochée à rien serait introuvable.**
     *
     * Ni sur une fiche client, ni sur une affaire : nulle part — et pourtant enregistrée. C'est la
     * saisie qu'on refait trois fois avant de comprendre qu'elle est bien partie quelque part.
     */
    public function testUneActiviteSansClientNiAffaireEstRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/commercial_activities', $entete + [
            'json' => ['type' => 'note', 'summary' => 'Note flottante.'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **Les relances d'un établissement ne sont pas celles du voisin.**
     *
     * Une fuite de cloisonnement ne produit pas d'erreur, elle produit des lignes en trop — ici, les
     * prochains gestes commerciaux d'un concurrent, ce qu'aucun exploitant n'accepterait de partager.
     */
    public function testLesRelancesDUnAutreEtablissementNeRemontentPas(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $this->activite($clientA, $enteteA, $this->idPayeur(), 'Echange sur A.', [
            'occurredAt' => '2026-08-10T10:00:00+00:00',
            'nextActionAt' => '2026-08-20',
            'nextAction' => 'Relance propre a A',
        ]);

        // L'etablissement A voit la sienne : sans cette assertion, le test passerait aussi avec un
        // fournisseur qui ne rend jamais rien.
        self::assertSame(['Relance propre a A'], $this->relances($clientA, $enteteA));

        [$clientB, $enteteB] = $this->agentSurGroupeB();
        self::assertNotContains(
            'Relance propre a A',
            $this->relances($clientB, $enteteB),
            'Les prochains gestes commerciaux de A n appartiennent pas a B.',
        );
    }

    // --- outillage --------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $extra
     */
    private function activite(object $client, array $entete, string $clientId, string $resume, array $extra = []): void
    {
        $client->request('POST', '/api/commercial_activities', $entete + [
            'json' => [
                'customer' => '/api/clients/' . $clientId,
                'type' => 'call',
                'summary' => $resume,
            ] + $extra,
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return list<string> les intitulés des relances en attente
     */
    private function relances(object $client, array $entete): array
    {
        $charge = $client->request('GET', '/api/crm/relances', $entete)->toArray();
        self::assertResponseIsSuccessful();

        return array_map(
            static fn (array $r): string => (string) $r['aFaire'],
            $charge['relances'],
        );
    }
}
