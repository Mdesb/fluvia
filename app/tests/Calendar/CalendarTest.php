<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\DataFixtures\SocleFixtures;
use App\Calendar\Entity\CalendarEvent;
use App\Calendar\Enum\CalendarEventType;
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

        // « mine » et non « moi » : le fournisseur lit `scope === 'mine' ? 'mine' : 'site'`, donc
        // « moi » retombait sur « site » et cette boucle passait DEUX FOIS par la portée du site.
        // Elle annonçait vérifier l'agenda personnel sans le vérifier. Le front, lui, traduit
        // (`onglet === 'moi' ? 'mine' : 'site'`) : c'est le test qui parlait le vocabulaire des
        // onglets au lieu de celui du fil.
        foreach (['site', 'mine'] as $portee) {
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
    /**
     * ⚠ L'ABONNEMENT DOIT CONTENIR CE QUE L'ONGLET « MOI » MONTRE.
     *
     * Le test voisin ne crée qu'un événement `siteWide: true` : il ne pouvait donc pas voir que le
     * flux ne servait QUE la portée du site. `IcsFeedController` demandait la portée « moi » à un
     * agrégateur qui n'entend que « mine » — il recevait le site deux fois, et l'abonné n'a jamais
     * vu ses propres rendez-vous dans son téléphone.
     *
     * Rien ne le signalait : le flux répond, il est valide, il contient des événements. On croit
     * avoir mal saisi son rendez-vous.
     */
    public function testLabonnementContientAussiMesEvenementsPersonnels(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Date calculée depuis maintenant : le flux ne publie qu'une fenêtre glissante.
        $dansUneSemaine = (new \DateTimeImmutable('+7 days'))->setTime(14, 0);
        $client->request('POST', '/api/calendar/calendar_events', $entete + [
            'json' => [
                'title' => 'Rendez-vous médical personnel',
                'start' => $dansUneSemaine->format(\DateTimeInterface::ATOM),
                'end' => $dansUneSemaine->modify('+1 hour')->format(\DateTimeInterface::ATOM),
                'type' => 'unavailability',
                // Pas de `siteWide` : l'événement n'appartient qu'à son auteur.
            ],
        ]);
        self::assertResponseIsSuccessful();

        $abonnement = $client->request('GET', '/api/calendar/ics-subscription', $entete)->toArray();
        self::assertResponseIsSuccessful();
        $path = $abonnement['member'][0]['path'];

        $corps = static::createClient()->request('GET', $path)->getContent();
        self::assertResponseIsSuccessful();

        // ⚠ ON DÉPLIE AVANT DE CHERCHER : au-delà de 75 octets une ligne est coupée par un CRLF
        // suivi d'une espace, et le titre ne se trouverait plus d'un seul tenant.
        self::assertStringContainsString(
            'SUMMARY:Rendez-vous médical personnel',
            str_replace("\r\n ", '', $corps),
            'Un abonné ne reçoit pas ses propres rendez-vous : le flux ne sert que la portée du site.',
        );
    }

    /**
     * ⚠ ET SES CRÉNEAUX DE TRAVAIL — le plus utile des deux, et le plus longtemps absent.
     *
     * `WorkShiftsCalendarSource` ne publie QUE pour la portée « mine ». Tant qu'elle n'était pas
     * servie, aucun employé n'a jamais vu son planning dans le calendrier de son téléphone. C'est
     * pourtant ce qu'on attend d'abord d'un agenda professionnel dans sa poche.
     *
     * Ce cas est distinct du précédent : un événement personnel est lu en base par l'agrégateur,
     * un créneau de travail vient d'un module TIERS par le port `CalendarSourceInterface`. Le
     * premier test ne dit rien du second chemin.
     */
    public function testLabonnementContientMesCreneauxDeTravail(): void
    {
        [$client, $entete] = $this->adminSurA();
        $em = $this->em();

        // ⚠ DEPUIS MAINTENANT, PAS UNE DATE FIXE. Le flux ne publie qu'une fenêtre glissante ; une
        // date de juin 2026 en est sortie, et le test serait vert sans rien mesurer.
        $dansTroisJours = (new \DateTimeImmutable('+3 days'))->setTime(14, 0);

        $creneauTravail = (new CreneauTravail())
            ->setEtablissement($this->etablissementDeA())
            ->setLibellePoste('Surveillance bassin du matin')
            ->setDebut($dansTroisJours)
            ->setFin($dansTroisJours->modify('+4 hours'));
        $em->persist($creneauTravail);

        $employe = (new Employe())
            ->setNom('Socle')
            ->setPrenom('Administratrice')
            ->setPoste('Régisseur')
            // `typeContrat` est obligatoire en base alors que la propriété PHP accepte `null` :
            // sans cette ligne, MariaDB refuse au `flush()` et le message ne nomme que la colonne.
            ->setTypeContrat(TypeContrat::Cdi)
            ->setDateEntree(new \DateTimeImmutable('2020-01-01'))
            ->setUtilisateur($this->utilisateurAdmin());
        $em->persist($employe);
        $em->persist((new AffectationTravail())->setCreneauTravail($creneauTravail)->setEmploye($employe));
        $em->flush();

        $abonnement = $client->request('GET', '/api/calendar/ics-subscription', $entete)->toArray();
        self::assertResponseIsSuccessful();
        $corps = static::createClient()->request('GET', $abonnement['member'][0]['path'])->getContent();

        self::assertStringContainsString(
            'Surveillance bassin du matin',
            str_replace("\r\n ", '', $corps),
            'Un employé abonné à son agenda ne voit pas ses créneaux de travail — la portée « mine » n’est pas servie.',
        );
    }

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

    /**
     * LA FRONTIÈRE D'ÉTABLISSEMENT TIENT AUSSI POUR MES PROPRES ÉVÉNEMENTS.
     *
     * Les tests d'isolation existants prennent l'événement d'un TIERS : la propriété les écarte
     * déjà, donc ils resteraient verts même si la borne d'établissement sautait. Le cas qui reste
     * découvert, c'est MON PROPRE événement chez le voisin — celui que la seule condition sur le
     * propriétaire laisserait passer.
     *
     * On l'écrit par l'`EntityManager` : l'API refuse — correctement — de créer hors du site actif,
     * donc elle ne sait pas fabriquer la ligne dont on veut vérifier qu'elle reste invisible.
     *
     * ⚠ SEULE L'ASSERTION SUR LA COLLECTION MESURE L'EXTENSION. Le fil (`/calendar/feed`) passe par
     * `CalendarAggregator`, qui reçoit l'établissement en paramètre : il ne consulte JAMAIS
     * `CalendarScopeExtension`. Les deux assertions sur le fil gardent l'agrégateur, ce qui est
     * utile, mais elles ne diraient rien d'une extension cassée. Les garder sans le dire ferait
     * croire à une couverture qu'on n'a pas.
     *
     * Filet vu attraper : en retirant la borne d'établissement de l'extension, ce test rougit.
     * (En retirant les parenthèses de la clause OR, non — Doctrine les remet, cf. l'extension.)
     */
    public function testMonPropreEvenementChezLeVoisinResteInvisible(): void
    {
        [$client, $entete] = $this->adminSurA();

        $voisin = $this->etablissementVoisin();
        $evenement = new CalendarEvent();
        $evenement->setEstablishment($voisin);
        $evenement->setOwner($this->utilisateurAdmin());
        $evenement->setTitle('Congé chez le voisin');
        $evenement->setStart(new \DateTimeImmutable('2026-06-05T09:00:00+00:00'));
        $evenement->setEnd(new \DateTimeImmutable('2026-06-05T10:00:00+00:00'));
        $evenement->setType(CalendarEventType::Unavailability);
        $this->em()->persist($evenement);
        $this->em()->flush();

        foreach (['site', 'mine'] as $portee) {
            $journal = $client->request('GET', '/api/calendar/feed?du=2026-06-05&au=2026-06-05&scope=' . $portee, $entete)->toArray();
            self::assertResponseIsSuccessful();
            self::assertNotContains(
                'Congé chez le voisin',
                array_column($journal['events'], 'title'),
                sprintf('Un événement d’un autre établissement a fui dans la portée « %s » — les parenthèses de la clause OR ont probablement sauté.', $portee),
            );
        }

        $collection = $client->request('GET', '/api/calendar/calendar_events', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotContains('Congé chez le voisin', array_column($collection['member'], 'title'));
    }

    /**
     * Le voisin est CHERCHÉ et non supposé : coder « Patinoire B » en dur ferait dépendre un test
     * de cloisonnement du nom d'un jeu de données.
     */
    private function etablissementVoisin(): Etablissement
    {
        $actif = $this->etablissementDeA();
        /** @var list<Etablissement> $etablissements */
        $etablissements = $this->em()->getRepository(Etablissement::class)->findAll();
        foreach ($etablissements as $candidat) {
            if (!$candidat->getId()->equals($actif->getId())) {
                return $candidat;
            }
        }

        self::fail('Aucun second établissement : ce test ne peut rien cloisonner.');
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
