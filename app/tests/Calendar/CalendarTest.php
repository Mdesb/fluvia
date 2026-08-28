<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Entity\Employe;
use App\Personnel\Enum\TypeContrat;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;

/**
 * L'AGENDA — deux onglets, une table, et une frontière qu'aucun droit ne lève.
 *
 * Maxime a tranché le 28/08 : « les deux, deux onglets ». « Le site » montre ce qui s'y passe ;
 * « Moi » montre ce que j'ai à faire. La distinction tient dans une colonne — `proprietaire` — et
 * c'est ce qui permet une seule saisie, un seul export ICS, un seul cloisonnement.
 *
 * ── LE TEST QUI COMPTE LE PLUS ──────────────────────────────────────────────────────────────────
 *
 * `testLEvenementPersonnelDUnAutreNestJamaisVisible` vérifie la seule propriété qui, si elle
 * cassait, ne se verrait jamais : un agenda personnel lisible par les collègues ne provoque aucune
 * erreur, aucun ralentissement, aucune alerte. Il se découvre le jour où quelqu'un s'en aperçoit,
 * c'est-à-dire trop tard. Et il est vérifié POUR L'ADMINISTRATEUR — le compte qui porte le joker
 * `*.*` — parce que c'est précisément lui qu'un « au cas où » aurait laissé passer.
 */
final class CalendarTest extends AccesApiTestCase
{
    public function testMonEvenementPersonnelNapparaitQueDansMonAgenda(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/calendar/calendar_events', $entete + [
            'json' => [
                'title' => 'Rendez-vous personnel',
                'start' => '2026-06-01T09:00:00+00:00',
                'end' => '2026-06-01T10:00:00+00:00',
                'type' => 'unavailability',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $moi = $client->request('GET', '/api/calendar/feed?du=2026-06-01&au=2026-06-01&scope=mine', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertContains('Rendez-vous personnel', array_column($moi['events'], 'title'));

        $site = $client->request('GET', '/api/calendar/feed?du=2026-06-01&au=2026-06-01&scope=site', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotContains('Rendez-vous personnel', array_column($site['events'], 'title'));
    }

    /**
     * L'INSTANT SURVIT À L'ALLER-RETOUR, ET C'EST CE QUI SE VÉRIFIE ICI.
     *
     * Le type Doctrine `datetime_immutable` ne convertit aucun fuseau : il écrit l'heure murale et
     * la relit dans le fuseau par défaut du processus. Une réunion envoyée `09:00+02:00` revenait
     * donc `09:00+00:00` — deux heures plus tard, sans que rien ne lève. Vu au navigateur le 28/08 :
     * une réunion créée à 9 h s'affichait à 11 h.
     *
     * ⚠ La comparaison porte sur l'INSTANT (`getTimestamp()`), jamais sur la chaîne. Comparer
     * « 09:00+02:00 » à lui-même serait vert quel que soit le stockage : c'est précisément le genre
     * d'assertion qui a laissé passer le défaut.
     */
    public function testLInstantSurvitALAllerRetourQuelQueSoitLeFuseauEnvoye(): void
    {
        [$client, $entete] = $this->adminSurA();

        $envoye = new \DateTimeImmutable('2026-06-10T09:00:00+02:00');
        $cree = $client->request('POST', '/api/calendar/calendar_events', $entete + [
            'json' => [
                'title' => 'Réunion à neuf heures, heure de Paris',
                'start' => $envoye->format(\DateTimeInterface::ATOM),
                'end' => $envoye->modify('+1 hour')->format(\DateTimeInterface::ATOM),
                'type' => 'meeting',
                'siteWide' => true,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame(
            $envoye->getTimestamp(),
            (new \DateTimeImmutable($cree['start']))->getTimestamp(),
            'L’instant a changé entre l’envoi et la relecture : l’heure murale a été stockée à la place du moment.',
        );

        // Et par le journal, qui est le chemin qu'emprunte réellement l'écran.
        $journal = $client->request('GET', '/api/calendar/feed?du=2026-06-10&au=2026-06-10&scope=site', $entete)->toArray();
        self::assertResponseIsSuccessful();
        $ligne = null;
        foreach ($journal['events'] as $e) {
            if ($e['title'] === 'Réunion à neuf heures, heure de Paris') {
                $ligne = $e;
            }
        }
        self::assertNotNull($ligne, 'Sans la ligne, l’assertion suivante serait vraie sans rien prouver.');
        self::assertSame($envoye->getTimestamp(), (new \DateTimeImmutable($ligne['start']))->getTimestamp());
    }

    public function testUnEvenementDuSiteApparaitDansLagendaDuSite(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/calendar/calendar_events', $entete + [
            'json' => [
                'title' => 'Réunion d’équipe',
                'start' => '2026-06-02T09:00:00+00:00',
                'end' => '2026-06-02T10:00:00+00:00',
                'type' => 'meeting',
                'siteWide' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $site = $client->request('GET', '/api/calendar/feed?du=2026-06-02&au=2026-06-02&scope=site', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertContains('Réunion d’équipe', array_column($site['events'], 'title'));
    }

    /**
     * ⚠ LA FRONTIÈRE QU'AUCUN DROIT NE LÈVE, ET ON LA VÉRIFIE SUR L'ADMINISTRATEUR.
     *
     * Le lecteur pose un événement personnel ; l'administrateur — porteur du joker `*.*` — ne le
     * voit ni dans l'agenda du site, ni dans le sien, ni dans la collection brute. Un blocage
     * personnel dans un agenda professionnel dit parfois autre chose qu'un horaire.
     */
    public function testLEvenementPersonnelDUnAutreNestJamaisVisible(): void
    {
        [$lecteur, $enteteLecteur] = $this->connecteLecteurSurA();
        $lecteur->request('POST', '/api/calendar/calendar_events', $enteteLecteur + [
            'json' => [
                'title' => 'Consultation médicale',
                'start' => '2026-06-03T09:00:00+00:00',
                'end' => '2026-06-03T10:00:00+00:00',
                'type' => 'unavailability',
            ],
        ]);
        self::assertResponseIsSuccessful('Sans cette création, les assertions suivantes seraient vraies sans rien prouver.');

        [$admin, $enteteAdmin] = $this->adminSurA();

        foreach (['site', 'moi'] as $portee) {
            $journal = $admin->request('GET', '/api/calendar/feed?du=2026-06-03&au=2026-06-03&scope=' . $portee, $enteteAdmin)->toArray();
            self::assertResponseIsSuccessful();
            self::assertNotContains(
                'Consultation médicale',
                array_column($journal['events'], 'title'),
                sprintf('L’agenda personnel d’un tiers ne doit pas apparaître dans la portée « %s ».', $portee),
            );
        }

        // Et pas davantage par la collection brute, qui est le chemin qu'on oublie de fermer.
        $collection = $admin->request('GET', '/api/calendar/calendar_events', $enteteAdmin)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotContains('Consultation médicale', array_column($collection['member'], 'title'));
    }

    /**
     * Le compte sans droit d'exploitation écrit dans SON agenda, jamais dans celui du site. Un
     * refus explicite, et non un événement silencieusement reclassé : reclasser laisserait croire
     * que la réunion est annoncée à toute l'équipe alors qu'elle n'est visible que de son auteur.
     */
    public function testEcrireDansLagendaDuSiteDemandeUnDroit(): void
    {
        [$lecteur, $entete] = $this->connecteLecteurSurA();

        $lecteur->request('POST', '/api/calendar/calendar_events', $entete + [
            'json' => [
                'title' => 'Réunion que ce compte ne peut pas annoncer',
                'start' => '2026-06-04T09:00:00+00:00',
                'end' => '2026-06-04T10:00:00+00:00',
                'type' => 'meeting',
                'siteWide' => true,
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeFluxIcsEstServiParSonJetonEtRevoqueParRegeneration(): void
    {
        [$client, $entete] = $this->adminSurA();

        // ⚠ UNE DATE CALCULÉE DEPUIS MAINTENANT, ET NON UNE DATE FIXE.
        //
        // Le flux ICS ne publie qu'une FENÊTRE GLISSANTE — un mois en arrière, trois en avant. Une
        // date fixe sort de cette fenêtre dès que le calendrier avance, et le test se met alors à
        // échouer sur l'assertion suivante : l'échappement de la virgule. Le message accuserait le
        // rédacteur ICS pour une erreur de date, et on chercherait au mauvais endroit.
        $dansUneSemaine = (new \DateTimeImmutable('+7 days'))->setTime(9, 0);
        $client->request('POST', '/api/calendar/calendar_events', $entete + [
            'json' => [
                'title' => 'Réunion, salle B',
                'start' => $dansUneSemaine->format(\DateTimeInterface::ATOM),
                'end' => $dansUneSemaine->modify('+1 hour')->format(\DateTimeInterface::ATOM),
                'type' => 'meeting',
                'siteWide' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $abonnement = $client->request('GET', '/api/calendar/ics-subscription', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($abonnement['member'], 'L’abonnement doit être créé à la première lecture.');
        $path = $abonnement['member'][0]['path'];
        self::assertMatchesRegularExpression('#^/calendar/ics/[0-9a-f]{48}\.ics$#', $path);

        // Le flux est ANONYME : aucun en-tête, c'est tout l'intérêt et tout le risque.
        $anonyme = static::createClient();
        $reponse = $anonyme->request('GET', $path);
        self::assertResponseIsSuccessful();
        $corps = $reponse->getContent();
        self::assertStringContainsString('BEGIN:VCALENDAR', $corps);
        // La virgule du titre DOIT être échappée : non échappée, elle coupe la propriété en deux et
        // l'événement s'appelle « Réunion » dans tous les agendas du monde.
        self::assertStringContainsString('SUMMARY:Réunion\\, salle B', $corps);
        // LE NOM DU PRODUIT, ET NON CELUI DU DÉPÔT. `PRODID` s'affiche dans l'agenda du client :
        // « Billetterie » y aurait nommé un dépôt que personne d'autre que nous ne connaît.
        self::assertStringContainsString('PRODID:-//Fluvia//Agenda//FR', $corps);
        self::assertStringNotContainsString('billetterie', $corps);
        // CRLF et non LF : Apple et Outlook refusent le fichier, sans jamais parler de fin de ligne.
        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $corps);

        // RÉGÉNÉRER, C'EST RÉVOQUER.
        $client->request('POST', '/api/calendar/ics-subscription/regenerate', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $anonyme->request('GET', $path);
        self::assertResponseStatusCodeSame(404, 'L’ancienne URL doit être inerte immédiatement.');
    }

    /** Un jeton inconnu rend 404 et non 403 : un 403 confirmerait qu'une URL voisine existe. */
    public function testUnJetonInconnuRend404(): void
    {
        $anonyme = static::createClient();
        $anonyme->request('GET', '/calendar/ics/' . str_repeat('a', 48) . '.ics');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * LES SOURCES SONT BRANCHÉES, ET CHACUNE PUBLIE DANS SA SEULE PORTÉE.
     *
     * ⚠ Ce test existe parce que les sept autres passaient DÉJÀ avant l'introduction du port — le
     * jeu de données ne contient ni créneau de réservation ni créneau de travail sur cet
     * établissement. Un port mal tagué, une méthode jamais appelée, un service non autowiré :
     * rien de tout cela n'aurait rougi. Un filet vert peut l'être pour une raison qui n'a rien à
     * voir.
     *
     * Les DEUX SENS sont vérifiés. N'en vérifier qu'un laisserait passer une source qui publie
     * tout partout — et l'agenda personnel d'un agent afficherait les quarante cours de la semaine.
     */
    public function testLesSourcesDesAutresModulesAlimententLaBonnePortee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->em();
        $etablissement = $this->etablissementDeA();

        $ressource = (new Ressource())
            ->setEtablissement($etablissement)
            ->setCodeType('bassin')
            ->setLibelle('Bassin sportif');
        $em->persist($ressource);

        $activite = (new Activite())->setEtablissement($etablissement)->setLibelle('Aquagym');
        $em->persist($activite);

        $creneau = (new Creneau())
            ->setEtablissement($etablissement)
            ->setRessource($ressource)
            ->setActivite($activite)
            ->setDebut(new \DateTimeImmutable('2026-06-15T09:00:00+00:00'))
            ->setFin(new \DateTimeImmutable('2026-06-15T10:00:00+00:00'));
        $em->persist($creneau);

        $creneauTravail = (new CreneauTravail())
            ->setEtablissement($etablissement)
            ->setLibellePoste('Surveillance bassin')
            ->setDebut(new \DateTimeImmutable('2026-06-15T14:00:00+00:00'))
            ->setFin(new \DateTimeImmutable('2026-06-15T18:00:00+00:00'));
        $em->persist($creneauTravail);

        $employe = (new Employe())
            ->setNom('Socle')
            ->setPrenom('Administratrice')
            ->setPoste('Régisseur')
            // ⚠ LE TYPE PHP AUTORISE CE QUE LA COLONNE INTERDIT — et ce n'est PAS une dérive de
            // mapping : `#[ORM\Column]` sans `nullable: true` déclare bien `NOT NULL`, et
            // l'`Assert\NotNull` est là. Mais la propriété est `?TypeContrat $typeContrat = null` :
            // une entité fraîchement construite est valide pour PHP, valide pour Doctrine, et
            // refusée par MariaDB au `flush()`. La validation Symfony ne s'exécute pas non plus,
            // puisqu'on persiste par l'`EntityManager` et non par l'API. D'où un message qui ne
            // nomme qu'une colonne. Diagnostic rectifié par claude-A le 28/08 — le mien disait
            // « mapping incomplet », et un garde-fou de dérive n'aurait jamais rien vu.
            // le refuse. Le message ne parle que de la colonne — pas de l'entité, pas du test.
            ->setTypeContrat(TypeContrat::Cdi)
            ->setDateEntree(new \DateTimeImmutable('2020-01-01'))
            ->setUtilisateur($this->utilisateurAdmin());
        $em->persist($employe);

        $em->persist((new AffectationTravail())->setCreneauTravail($creneauTravail)->setEmploye($employe));
        $em->flush();

        $site = $client->request('GET', '/api/calendar/feed?du=2026-06-15&au=2026-06-15&scope=site', $entete)->toArray();
        self::assertResponseIsSuccessful();
        $titresSite = array_column($site['events'], 'title');
        self::assertContains('Aquagym · Bassin sportif', $titresSite, 'Le créneau de réservation doit alimenter « le site ».');
        self::assertNotContains('Surveillance bassin', $titresSite, 'Le planning de l’équipe n’a rien à faire dans « le site ».');

        $moi = $client->request('GET', '/api/calendar/feed?du=2026-06-15&au=2026-06-15&scope=mine', $entete)->toArray();
        self::assertResponseIsSuccessful();
        $titresMoi = array_column($moi['events'], 'title');
        self::assertContains('Surveillance bassin', $titresMoi, 'Mon créneau de travail doit alimenter « moi ».');
        self::assertNotContains('Aquagym · Bassin sportif', $titresMoi, 'Les quarante cours de la semaine ne sont pas mon agenda.');
    }

    private function etablissementDeA(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)
            ->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etablissement);

        return $etablissement;
    }

    private function utilisateurAdmin(): Utilisateur
    {
        $utilisateur = $this->em()->getRepository(Utilisateur::class)
            ->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertNotNull($utilisateur);

        return $utilisateur;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    private function connecteLecteurSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        return [$client, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]];
    }
}
