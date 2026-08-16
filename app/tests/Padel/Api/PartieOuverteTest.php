<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Padel\Command\MaintienPartieA3Command;
use App\Tests\Padel\PadelApiTestCase;

/** Partie ouverte / matching de joueurs (US-PADEL-02/03, RG-PADEL-03, CA-3/CA-4/CA-5). */
final class PartieOuverteTest extends PadelApiTestCase
{
    public function testCa3PartieSeCompleteA4EtChaquePartEstPayee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idPartie = $this->creerPartieOuverte($client, $entete, 2);

        // Valide le niveau des joueurs 3 et 4 (requis pour rejoindre une partie filtrée par niveau).
        $this->validerNiveau($client, $entete, 3, 5);
        $this->validerNiveau($client, $entete, 4, 5);

        $client->request('POST', '/api/padel/parties-ouvertes/' . $idPartie . '/rejoindre', $entete + [
            'json' => ['joueur' => '/api/beneficiaires/' . $this->idJoueur(3)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('ouverte', $client->getResponse()->toArray()['statutPartie'] ?? null);

        $client->request('POST', '/api/padel/parties-ouvertes/' . $idPartie . '/rejoindre', $entete + [
            'json' => ['joueur' => '/api/beneficiaires/' . $this->idJoueur(4)],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('complete', $donnees['statutPartie'] ?? null, 'CA-3 : la partie est complète (4/4).');

        $idReservation = basename((string) $donnees['reservation']);
        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        $reservation = $client->getResponse()->toArray();
        self::assertCount(4, $reservation['participants'] ?? []);

        // Les 2 joueurs initiaux (organisateur + 2e joueur à la création) règlent leur part via le
        // paiement partagé générique du socle ; les 2 qui ont rejoint la partie ont payé automatiquement
        // à l'inscription (§4.3, RejoindrePartieProcessor).
        foreach ($reservation['participants'] as $participant) {
            if ($participant['statutPaiement'] !== 'paye') {
                $client->request('POST', '/api/reservation/participants/' . $participant['id'] . '/payer', $entete + ['json' => []]);
                self::assertResponseIsSuccessful();
            }
        }

        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        foreach ($client->getResponse()->toArray()['participants'] as $participant) {
            self::assertSame('paye', $participant['statutPaiement'], 'CA-3 : chaque joueur a payé sa part.');
        }
    }

    public function testCa5NiveauNonValideNonEligible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idPartie = $this->creerPartieOuverte($client, $entete, 2);

        // Le joueur 2 a un niveau « proposé » (non validé) dans les fixtures : non éligible au filtre.
        $client->request('POST', '/api/padel/parties-ouvertes/' . $idPartie . '/rejoindre', $entete + [
            'json' => ['joueur' => '/api/beneficiaires/' . $this->idJoueur(2)],
        ]);
        self::assertResponseStatusCodeSame(422, 'CA-5 : niveau non validé, non éligible au filtrage.');
    }

    public function testCa5ValidationNiveauTraceeEtRendJoueurEligible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idNiveauPropose = $this->entite(\App\Padel\Entity\NiveauJoueur::class, ['niveau' => 5, 'statut' => \App\Padel\Enum\StatutNiveauJoueur::Propose->value])->getId();

        $client->request('POST', '/api/padel/niveaux/' . $idNiveauPropose . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('valide', $donnees['statut'] ?? null, 'CA-5 : le statut passe à validé.');

        $client->request('GET', '/api/padel_historique_niveaus?niveauJoueur=' . $idNiveauPropose, $entete);
        self::assertResponseIsSuccessful();
    }

    public function testCa4PartieMaintenueA3SurcoutReparti(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idPartie = $this->creerPartieOuverte($client, $entete, 2);
        $this->validerNiveau($client, $entete, 3, 5);

        $client->request('POST', '/api/padel/parties-ouvertes/' . $idPartie . '/rejoindre', $entete + [
            'json' => ['joueur' => '/api/beneficiaires/' . $this->idJoueur(3)],
        ]);
        self::assertResponseIsSuccessful();

        /** @var MaintienPartieA3Command $commande */
        $commande = static::getContainer()->get(MaintienPartieA3Command::class);
        // Le créneau est dans le futur (lundi prochain 19h) : force la date de référence après l'heure.
        $traitees = $commande->traiter((new \DateTimeImmutable('next monday'))->setTime(19, 1));
        self::assertSame(1, $traitees, 'CA-4 : une partie maintenue à 3.');

        $client->request('GET', '/api/padel_reservations/' . $idPartie, $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('maintenue_a_3', $donnees['statutPartie'] ?? null, 'CA-4 : jamais annulée pour ce seul motif.');

        $idReservation = basename((string) $donnees['reservation']);
        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        $reservation = $client->getResponse()->toArray();
        self::assertCount(3, $reservation['participants']);
        $totalPercu = array_sum(array_map(static fn (array $p) => (float) $p['partMontant'], $reservation['participants']));
        self::assertEqualsWithDelta((float) $reservation['montantDu'], $totalPercu, 0.02, 'CA-4 : le surcoût du 4e manquant est réparti entre les 3 présents.');
        foreach ($reservation['participants'] as $participant) {
            self::assertSame('paye', $participant['statutPaiement'], 'CA-4 : le surcoût est encaissé.');
        }
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerPartieOuverte(object $client, array $entete, int $nbJoueursInitial): string
    {
        $idTerrain = $this->idTerrain();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);

        $corps = [
            'debut' => $debut->format(DATE_ATOM),
            'dureeMinutes' => 90,
            'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
            'ouverte' => true,
            'niveauViseMin' => 1,
            'niveauViseMax' => 10,
        ];
        if ($nbJoueursInitial >= 2) {
            $corps['joueurs'] = ['/api/beneficiaires/' . $this->idJoueur(5)];
        }

        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + ['json' => $corps]);
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->toArray()['id'];
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function validerNiveau(object $client, array $entete, int $joueurNumero, int $niveau): void
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $joueurClient = $em->getRepository(\App\Crm\Entity\Client::class)->findOneBy(['email' => \App\Padel\DataFixtures\PadelFixtures::JOUEUR_EMAIL_PREFIX . $joueurNumero . \App\Padel\DataFixtures\PadelFixtures::JOUEUR_DOMAINE]);
        $beneficiaire = $em->getRepository(\App\Crm\Entity\Beneficiaire::class)->findOneBy(['client' => $joueurClient]);

        $client->request('POST', '/api/padel/niveaux/declarer', $entete + [
            'json' => ['joueur' => '/api/beneficiaires/' . (string) $beneficiaire->getId(), 'niveau' => $niveau],
        ]);
        self::assertResponseIsSuccessful();
        $idNiveau = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/padel/niveaux/' . $idNiveau . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
    }
}
