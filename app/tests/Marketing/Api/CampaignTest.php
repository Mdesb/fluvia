<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Api;

use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Marketing\Entity\Campaign;
use App\Marketing\Entity\Segment;
use App\Marketing\Entity\SegmentCriteria;
use App\Marketing\Enum\ExclusionReason;
use App\Tests\Marketing\MarketingApiTestCase;

/**
 * TOUT SE JOUE À L'ENVOI — et ce qui compte, c'est qui n'a PAS reçu le message.
 *
 * Une campagne qui part est facile à écrire. Ce qui est difficile, et ce que ces tests vérifient,
 * c'est que les bonnes personnes sont écartées, pour le bon motif, et que l'exploitant l'apprend.
 *
 * > **« 1 240 ciblés, 310 exclus faute de consentement » est plus utile que « 930 envoyés ».**
 */
final class CampaignTest extends MarketingApiTestCase
{
    /**
     * **CA-2 et CA-3 : sans consentement accordé, on n'écrit pas.**
     *
     * Le contrôle ne vit pas dans le service d'envoi mais dans le décorateur posé sur l'unique
     * chemin vers un client. Ce test le vérifie **par l'effet**, sans rien savoir de son
     * emplacement — c'est la seule façon de garantir qu'un futur second service d'envoi soit
     * couvert lui aussi.
     */
    public function testSansConsentementPersonneNEstContacte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $campagne = $this->campagne('Relance de saison');

        $resultat = $client->request('POST', '/api/marketing/campagnes/' . $campagne->getId() . '/envoyer', $entete)
            ->toArray();

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey(
            'journalise',
            $resultat['resultat'],
            'Aucune fixture n’accorde de consentement e-mail : personne ne doit être contacté.',
        );
        self::assertArrayHasKey(ExclusionReason::SansConsentement->value, $resultat['resultat']);
    }

    /**
     * **Le versant qui empêche le test précédent de passer sur un envoi cassé.**
     *
     * Sans lui, un service qui n'enverrait jamais rien — quelle qu'en soit la raison — passerait
     * pour un service qui respecte le consentement. On accorde donc un consentement, et on exige
     * que quelqu'un soit contacté.
     */
    public function testAvecConsentementLeClientEstContacte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $destinataire = $this->clientAvecEmail();
        $this->accorderConsentement($destinataire);

        // Pas de groupe témoin : avec un seul consentant, un tirage de 10 % pourrait l'emporter et
        // le test dirait « personne contacté » pour une raison qui n'a rien à voir.
        $campagne = $this->campagne('Relance ciblée', temoin: 0);

        $resultat = $client->request('POST', '/api/marketing/campagnes/' . $campagne->getId() . '/envoyer', $entete)
            ->toArray();

        self::assertSame(1, $resultat['resultat']['journalise'] ?? 0);
        self::assertFalse(
            $resultat['envoiReelDisponible'],
            'Rien n’est réellement parti : le dire est la seule attitude honnête tant qu’aucun prestataire n’existe.',
        );
    }

    /**
     * **CA-4 : une variable non résolue écarte cette personne, sans bloquer les autres.**
     *
     * « Bonjour , » coûte plus cher en confiance qu'il ne rapporte en visites. Mais bloquer toute la
     * campagne parce qu'un client n'a pas de prénom serait une punition collective.
     */
    public function testUneVariableNonResolueEcarteLaSeulePersonneConcernee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $sansPrenom = $this->clientAvecEmail();
        $sansPrenom->setPrenom(null);
        $this->accorderConsentement($sansPrenom);

        $campagne = $this->campagne('Avec prénom', temoin: 0, corps: 'Bonjour {{prenom}}, revenez nous voir.');

        $resultat = $client->request('POST', '/api/marketing/campagnes/' . $campagne->getId() . '/envoyer', $entete)
            ->toArray();

        self::assertSame(1, $resultat['resultat'][ExclusionReason::VariableNonResolue->value] ?? 0);
    }

    /**
     * **Une variable que le serveur ne sait pas remplir est refusée à l'ÉCRITURE.**
     *
     * Sinon l'exploitant l'apprendrait sur un rapport disant « 1 240 exclus » — après avoir rédigé
     * son message et cliqué sur envoyer.
     */
    public function testUneVariableInconnueEstRefuseeALEcriture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $segment = $this->segment();

        $client->request('POST', '/api/campaigns', $entete + [
            'json' => [
                'label' => 'Solde de carte',
                'segment' => '/api/segments/' . $segment->getId(),
                'subject' => 'Votre carte',
                'body' => 'Il vous reste {{solde_carte}} entrées.',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **LE CHEMIN NOMINAL — celui qu'aucun test ne parcourait.**
     *
     * Tous les tests d'écriture de ce fichier attendent un REFUS : variable inconnue, canal
     * interdit, périmètre. Aucun n'allait jusqu'à l'enregistrement. Résultat : `Campaign` utilisait
     * le processeur de tampon d'établissement, ce processeur ne traitait que `Segment`, et créer
     * une campagne depuis l'écran répondait 500 sur une colonne `NOT NULL`.
     *
     * > **Un chemin nominal que personne ne parcourt en test est un chemin qu'on découvre en
     * > production.**
     *
     * L'établissement n'est PAS envoyé par le corps de la requête, et c'est le point du test : il
     * doit être posé par le serveur (D41). L'accepter du client permettrait de créer une campagne
     * chez le voisin.
     */
    public function testUneCampagneSeCreeParLApiEtRecoitSonEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $segment = $this->segment();

        $cree = $client->request('POST', '/api/campaigns', $entete + [
            'json' => [
                'label' => 'Nocturne de septembre',
                'segment' => '/api/segments/' . $segment->getId(),
                'subject' => 'Une nocturne vous attend',
                'body' => 'Nous serions heureux de vous revoir.',
            ],
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            '/api/etablissements/' . $this->etablissementA()->getId(),
            $cree['establishment'] ?? null,
            'L’établissement doit être posé par le serveur, jamais reçu du client (D41).',
        );
    }

    /**
     * **CA-5 : le groupe témoin n'est pas contacté, et il apparaît dans le résultat.**
     *
     * Il est compté à part, jamais parmi les exclus. Le confondre avec un raté ferait croire qu'on a
     * manqué une part de son audience — et ferait disparaître le seul point de comparaison qui dit
     * si la campagne a servi à quelque chose.
     */
    public function testLeGroupeTemoinEstCompteAPartEtNonContacte(): void
    {
        [$client, $entete] = $this->adminSurA();
        foreach ($this->clientsAvecEmail() as $destinataire) {
            $this->accorderConsentement($destinataire);
        }

        $campagne = $this->campagne('Avec témoin', temoin: 50);

        $client->request('POST', '/api/marketing/campagnes/' . $campagne->getId() . '/envoyer', $entete);
        $resultat = $client->request('GET', '/api/marketing/campagnes/' . $campagne->getId() . '/resultat', $entete)
            ->toArray();

        self::assertGreaterThan(0, $resultat['temoins'], 'La moitié de l’audience doit être en témoin.');
        self::assertSame(
            $resultat['cibles'],
            $resultat['contactes'] + $resultat['temoins']
                + array_sum(array_column($resultat['exclus'], 'nombre')),
            'Chaque personne ciblée est dans exactement une case : contactée, témoin ou exclue.',
        );
    }

    /**
     * **Une campagne déjà partie ne se rejoue pas.**
     *
     * Rejouer recontacterait des gens qui ont déjà reçu le message. C'est la façon la plus simple de
     * brûler un canal, et un double-clic suffirait — d'où un 409 explicite plutôt qu'un second envoi
     * silencieux.
     */
    public function testUneCampagneDejaEnvoyeeNeSeRejouePas(): void
    {
        [$client, $entete] = $this->adminSurA();
        $campagne = $this->campagne('Une seule fois');

        $client->request('POST', '/api/marketing/campagnes/' . $campagne->getId() . '/envoyer', $entete);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/marketing/campagnes/' . $campagne->getId() . '/envoyer', $entete);
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * **La campagne d'un autre établissement est introuvable, pas interdite.**
     *
     * 404 et non 403 : distinguer « hors périmètre » de « inexistante » permettrait d'énumérer les
     * campagnes du voisin en essayant des identifiants — et le nom d'une campagne dit souvent ce
     * qu'un exploitant prépare.
     */
    public function testLaCampagneDUnAutreEtablissementEstIntrouvable(): void
    {
        $campagne = $this->campagne('Offre de rentrée');

        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $clientB->request('POST', '/api/marketing/campagnes/' . $campagne->getId() . '/envoyer', $enteteB);

        self::assertSame(404, $reponse->getStatusCode());
        self::assertStringNotContainsString('Offre de rentrée', $reponse->getContent(false));
    }

    // --- outillage --------------------------------------------------------------------------------

    private function campagne(
        string $libelle,
        int $temoin = Campaign::TEMOIN_PAR_DEFAUT,
        string $corps = 'Nous serions heureux de vous revoir.',
    ): Campaign {
        $em = $this->em();
        $campagne = (new Campaign())
            ->setEstablishment($this->etablissementA())
            ->setSegment($this->segment())
            ->setLabel($libelle)
            ->setSubject('Un message')
            ->setBody($corps)
            ->setControlGroupPercent($temoin);
        $em->persist($campagne);
        $em->flush();

        return $campagne;
    }

    private function segment(): Segment
    {
        $em = $this->em();
        $existant = $em->getRepository(Segment::class)->findOneBy(['label' => 'Cible de test']);
        if ($existant instanceof Segment) {
            return $existant;
        }

        $segment = (new Segment())
            ->setEstablishment($this->etablissementA())
            ->setLabel('Cible de test')
            ->setCriteria([SegmentCriteria::TYPE => 'physique']);
        $em->persist($segment);
        $em->flush();

        return $segment;
    }

    /** @return list<Client> */
    private function clientsAvecEmail(): array
    {
        /** @var list<Client> $clients */
        $clients = $this->em()->getRepository(Client::class)->createQueryBuilder('c')
            ->andWhere('c.email IS NOT NULL')
            ->andWhere('c.prenom IS NOT NULL')
            ->getQuery()->getResult();
        self::assertNotEmpty($clients, 'Les fixtures CRM doivent poser des clients joignables.');

        return $clients;
    }

    private function clientAvecEmail(): Client
    {
        return $this->clientsAvecEmail()[0];
    }

    private function accorderConsentement(Client $client): void
    {
        $em = $this->em();
        $em->persist(
            (new Consentement())
                ->setClient($client)
                ->setCanal(CanalConsentement::Email)
                ->setEtat(EtatConsentement::Accorde)
                ->setDateRecueil(new \DateTimeImmutable('-1 day'))
                ->setSource('test')
        );
        $em->flush();
    }
}
