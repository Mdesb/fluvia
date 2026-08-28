<?php

declare(strict_types=1);

namespace App\Tests\Opening;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Opening\Entity\OpeningException;
use App\Opening\Entity\OpeningSlot;
use App\Opening\Entity\OpeningSetting;
use App\Opening\Enum\OpeningExceptionType;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LE PLANNING D'OUVERTURE, ET CE QU'IL FAIT AU CONTRÔLE D'ACCÈS.
 *
 * Demandé par Maxime le 28/08 : *« il faut un planning d'ouverture (lié au contrôle d'accès s'il y
 * a) »*, avec son arbitrage — **refus hors horaires activable par site, jamais appliqué par
 * défaut**.
 *
 * ── LE PREMIER TEST EST LE PLUS IMPORTANT, ET C'EST CELUI QU'ON N'ÉCRIT PAS D'HABITUDE ──────────
 *
 * `testLeReglageEteintNeRefuseRien` vérifie qu'un site qui saisit ses horaires SANS cocher la case
 * ne se met pas à refuser du monde. C'est la propriété qui protège les installations existantes le
 * jour du déploiement, et c'est exactement le genre de propriété qu'aucun test ne couvre parce
 * qu'elle décrit ce qui NE DOIT PAS arriver. Une file à l'entrée un samedi matin ne se rattrape pas
 * par un correctif le lundi.
 *
 * ── LE FUSEAU A SON PROPRE TEST, ET IL LE MÉRITE ────────────────────────────────────────────────
 *
 * Les horaires sont saisis en heure locale ; le passage arrive en UTC ; le conteneur PHP tourne en
 * UTC. Sans conversion, toute la grille se décale de deux heures l'été — un site refuserait ses
 * adhérents jusqu'à 11 h et les laisserait entrer jusqu'à 20 h. Le défaut est invisible en hiver à
 * une heure près, et invisible tout court pour qui teste en UTC.
 */
final class OpeningScheduleTest extends AccesApiTestCase
{
    /** Lundi 1er juin 2026. En France, heure d'été : UTC+2. */
    private const LUNDI = '2026-06-01';
    private const SAMEDI = '2026-06-06';
    private const DIMANCHE = '2026-06-07';

    public function testLeReglageEteintNeRefuseRien(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Un planning qui n'ouvre QUE le lundi 9 h - 18 h, et un passage un dimanche à 3 h du matin.
        // Tout y est réuni pour un refus — sauf la case, que personne n'a cochée.
        $this->poserPlage(1, '09:00', '18:00');

        $reponse = $this->passage($client, $entete, self::DIMANCHE . 'T01:00:00+00:00');
        self::assertSame('valide', $reponse['resultat'], 'Un planning non appliqué ne doit RIEN refuser.');
    }

    public function testUneFoisEnVigueurLeSiteFermeRefuseLePassage(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->poserPlage(1, '09:00', '18:00');
        $this->mettreEnVigueur(true);

        $reponse = $this->passage($client, $entete, self::DIMANCHE . 'T01:00:00+00:00');
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('hors_horaires_ouverture', $reponse['codeMotif']);
    }

    public function testDansLesHorairesLePassagePasse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->poserPlage(1, '09:00', '18:00');
        $this->mettreEnVigueur(true);

        // 12 h UTC = 14 h à Paris en juin : au cœur de la plage.
        $reponse = $this->passage($client, $entete, self::LUNDI . 'T12:00:00+00:00');
        self::assertSame('valide', $reponse['resultat']);
    }

    /**
     * LE FUSEAU FAIT FOI, ET IL SE VÉRIFIE AUX DEUX BORNES.
     *
     * 06:30 UTC = 08:30 à Paris → fermé. 07:30 UTC = 09:30 → ouvert. Un code qui compare des heures
     * UTC brutes rendrait l'inverse pour la première et le même résultat pour la seconde : tester
     * une seule borne n'aurait donc rien prouvé.
     */
    public function testLHeureSeLitDansLeFuseauDeLEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->poserPlage(1, '09:00', '18:00');
        $this->mettreEnVigueur(true);

        $avant = $this->passage($client, $entete, self::LUNDI . 'T06:30:00+00:00');
        self::assertSame('refuse', $avant['resultat'], '08 h 30 locales : le site n’a pas encore ouvert.');
        self::assertSame('hors_horaires_ouverture', $avant['codeMotif']);

        $apres = $this->passage($client, $entete, self::LUNDI . 'T07:30:00+00:00');
        self::assertSame('valide', $apres['resultat'], '09 h 30 locales : le site est ouvert.');
    }

    /**
     * Une soirée « samedi 22 h → 02 h » couvre deux heures du DIMANCHE. Chercher les tranches du
     * seul jour du passage la manquerait, et un adhérent serait refusé au milieu de la nocturne.
     */
    public function testLaTrancheQuiTraverseMinuitCouvreLePetitMatinDuLendemain(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->poserPlage(6, '22:00', '02:00', 'Nocturne');
        $this->mettreEnVigueur(true);

        // 23 h UTC le samedi = 1 h du matin le dimanche à Paris : dans la nocturne.
        $reponse = $this->passage($client, $entete, self::SAMEDI . 'T23:00:00+00:00');
        self::assertSame('valide', $reponse['resultat']);

        // 01 h UTC le dimanche = 3 h locales : la nocturne est finie.
        $apres = $this->passage($client, $entete, self::DIMANCHE . 'T01:00:00+00:00');
        self::assertSame('refuse', $apres['resultat']);
        self::assertSame('hors_horaires_ouverture', $apres['codeMotif']);
    }

    public function testUneFermetureExceptionnelleFermeLaJourneeMalgreLaPlage(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->poserPlage(1, '09:00', '18:00');
        $this->poserException(self::LUNDI, OpeningExceptionType::Closure, null, null, 'Jour férié');
        $this->mettreEnVigueur(true);

        $reponse = $this->passage($client, $entete, self::LUNDI . 'T12:00:00+00:00');
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('hors_horaires_ouverture', $reponse['codeMotif']);
    }

    /**
     * L'ordre compte : la fermeture se retranche APRÈS l'ouverture exceptionnelle. Une nocturne
     * saisie l'an dernier ne doit pas rouvrir un jour férié décidé la semaine dernière.
     */
    public function testUnJourFermeSOuvrePourUneOccasionSpeciale(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->poserPlage(1, '09:00', '18:00');
        $this->poserException(self::DIMANCHE, OpeningExceptionType::SpecialOpening, '10:00', '13:00', 'Portes ouvertes');
        $this->mettreEnVigueur(true);

        // 09 h UTC = 11 h locales, dans l'ouverture exceptionnelle d'un jour autrement fermé.
        $reponse = $this->passage($client, $entete, self::DIMANCHE . 'T09:00:00+00:00');
        self::assertSame('valide', $reponse['resultat']);
    }

    /**
     * Le planning résolu est ce que l'agenda dessine. Il doit dire les fenêtres ET s'il fait loi :
     * un écran qui montrerait des horaires sans dire qu'ils sont inertes serait un écran qui ment
     * par omission.
     */
    public function testLePlanningResoluRendLesFenetresEtLEtatDuReglage(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->poserPlage(1, '09:00', '18:00', 'Journée');
        $this->mettreEnVigueur(true);

        // Une exception le même jour : elle doit REVENIR dans la réponse.
        //
        // ⚠ CETTE ASSERTION EXISTE POUR UNE RAISON PRÉCISE. La requête qui la charge lie un
        // identifiant d'établissement ; sans son type `uuid`, Doctrine rend zéro ligne — sans
        // exception, sans avertissement (D58). Le garde-fou l'a attrapée, aucun test ne l'aurait
        // fait : la liste n'était assertée nulle part, donc « vide » et « correct » étaient
        // indiscernables.
        $this->poserException(self::LUNDI, OpeningExceptionType::Closure, '12:00', '14:00', 'Coupure méridienne');

        $planning = $client->request('GET', '/api/opening/schedule?du=' . self::LUNDI . '&au=' . self::LUNDI, $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertTrue($planning['enforced']);
        self::assertCount(1, $planning['exceptions'], 'L’exception du jour doit revenir dans le planning résolu.');
        self::assertSame('Coupure méridienne', $planning['exceptions'][0]['reason']);
        self::assertFalse($planning['exceptions'][0]['allDay']);
        self::assertSame('Europe/Paris', $planning['timezone']);
        // DEUX fenêtres et non une : la coupure de 12 h à 14 h scinde la plage 9 h - 18 h. Une
        // soustraction qui rendrait une seule fenêtre laisserait le site réputé ouvert à midi.
        self::assertCount(2, $planning['windows']);
        self::assertSame(self::LUNDI, $planning['windows'][0]['day']);
        self::assertSame('Journée', $planning['windows'][0]['label']);
        // ATOM et non « 09:00 » : une fenêtre qui traverse minuit finit un autre jour, et une heure
        // nue ne sait pas le dire.
        self::assertStringStartsWith(self::LUNDI . 'T09:00:00', $planning['windows'][0]['start']);
    }

    /**
     * ⚠ CE TEST GARDE LA PORTE DU MODULE. `etablissement` n'est pas dans le groupe d'écriture, mais
     * l'ESPACE, lui, arrive du corps de requête : les extensions Doctrine ne filtrent que les
     * lectures (D8), et un espace du voisin accroché à nos horaires laisserait décider quand SA
     * porte s'ouvre.
     */
    public function testUnEspaceDUnAutreEtablissementEstRefuseALEcriture(): void
    {
        [$client, $entete] = $this->adminSurA();

        // On le FABRIQUE au lieu de sauter le test : « Patinoire B » existe dans `SocleFixtures`,
        // il lui manquait seulement un espace d'accès. Un test de cloisonnement qui se saute
        // affiche un `S` vert et ne vérifie rien.
        $espaceAilleurs = $this->creerEspaceChezLeVoisin();

        // `weekday` et non `day` : le renommage en anglais avait traduit cette cle de charge
        // utile avec les cles de la reponse agregee, qui portent bien `day`. L'ecriture echouait
        // de toute facon sur l'espace, donc rien ne l'avait signale.
        $client->request('POST', '/api/opening/opening_slots', $entete + [
            'json' => [
                'weekday' => 1,
                'startTime' => '09:00:00',
                'endTime' => '18:00:00',
                'space' => '/api/espace_acces/' . $espaceAilleurs,
            ],
        ]);

        // ── ON ASSERTE L'EFFET, PAS LE CODE ────────────────────────────────────────────────────
        //
        // DEUX remparts refusent ce rattachement, et le premier a changé le 28/08 : depuis que les
        // lectures d'`EspaceAcces` suivent l'établissement actif, le dénormaliseur ne résout plus
        // l'IRI du voisin (400) et `OpeningWriteProcessor` n'est même plus atteint (il rendait 422).
        //
        // Figer le code de statut ferait dépendre ce test du module d'un autre. « Aucune tranche
        // n'a été écrite chez le voisin » reste vrai quel que soit le rempart qui refuse — et le
        // restera si un troisième s'ajoute.
        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        self::assertLessThan(500, $client->getResponse()->getStatusCode(), 'Un refus, pas une panne.');

        // ⚠ VÉRIFICATION PAR L'`EntityManager`, JAMAIS PAR L'API. La collection est bornée à
        // l'établissement actif : l'interroger rendrait une liste vide MÊME si l'écriture avait
        // réussi chez le voisin. Un test qui lirait par l'API serait vert pour la mauvaise raison.
        $chezLeVoisin = $this->em()->getRepository(OpeningSlot::class)->findBy([
            'space' => $this->em()->getRepository(EspaceAcces::class)->find(Uuid::fromString($espaceAilleurs)),
        ]);
        self::assertSame([], $chezLeVoisin, 'Une tranche a été écrite sur l’espace d’un autre établissement.');
    }

    /**
     * LES JOURS FÉRIÉS SONT PROPOSÉS, ET CE QUI EST DÉJÀ FERMÉ N'EST PAS REPROPOSÉ.
     *
     * Sans `alreadyClosed`, l'écran cocherait Noël à un exploitant qui l'a fermé l'an dernier — et
     * une case cochée qui ne correspond à rien apprend à ne plus lire les cases.
     */
    public function testLesFeriesSontProposesEtDisentCeQuiEstDejaFerme(): void
    {
        [$client, $entete] = $this->adminSurA();

        $this->poserException('2026-12-25', OpeningExceptionType::Closure, null, null, 'Noël');

        $indices = $client->request('GET', '/api/opening/calendar-hints?from=2026-01-01&to=2026-12-31', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($indices['publicHolidays'], 'Sans férié, les assertions suivantes ne prouveraient rien.');

        $parDate = [];
        foreach ($indices['publicHolidays'] as $ferie) {
            $parDate[$ferie['date']] = $ferie;
        }

        self::assertArrayHasKey('2026-12-25', $parDate);
        self::assertTrue($parDate['2026-12-25']['alreadyClosed'], 'Noël est déjà fermé : ne pas le reproposer.');
        self::assertArrayHasKey('2026-07-14', $parDate);
        self::assertFalse($parDate['2026-07-14']['alreadyClosed']);

        // Hors Alsace-Moselle par défaut : ni Vendredi saint, ni 26 décembre.
        self::assertArrayNotHasKey('2026-04-03', $parDate);
        self::assertArrayNotHasKey('2026-12-26', $parDate);
    }

    /**
     * Sans zone choisie, on ne devine pas : le fournisseur rend la main AVANT d'appeler le
     * ministère, et l'écran reçoit une phrase qui dit quoi faire plutôt qu'une liste vide.
     */
    public function testSansZoneScolaireOnNInterrogePersonneEtOnLeDit(): void
    {
        [$client, $entete] = $this->adminSurA();

        $indices = $client->request('GET', '/api/opening/calendar-hints?from=2026-01-01&to=2026-06-30', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNull($indices['schoolZone']);
        self::assertSame([], $indices['schoolHolidays']);
        self::assertNotNull($indices['schoolHolidaysReason'], 'L’écran doit pouvoir dire pourquoi il n’affiche rien.');
    }

    // ── Outillage ───────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function passage(object $client, array $entete, string $horodatage): array
    {
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'horodatage' => $horodatage,
            ],
        ]);
        self::assertResponseIsSuccessful('L’ingestion doit aboutir : un refus est une RÉPONSE, pas une erreur HTTP.');

        return $client->getResponse()->toArray();
    }

    private function poserPlage(int $jour, string $debut, string $fin, ?string $libelle = null): void
    {
        $em = $this->em();
        $plage = (new OpeningSlot())
            ->setEstablishment($this->siteActif())
            ->setWeekday($jour)
            ->setStartTime(new \DateTimeImmutable($debut . ':00'))
            ->setEndTime(new \DateTimeImmutable($fin . ':00'))
            ->setLabel($libelle);
        $em->persist($plage);
        $em->flush();
    }

    private function poserException(string $date, OpeningExceptionType $type, ?string $debut, ?string $fin, string $reason): void
    {
        $em = $this->em();
        $exception = (new OpeningException())
            ->setEstablishment($this->siteActif())
            ->setDate(new \DateTimeImmutable($date))
            ->setType($type)
            ->setStartTime($debut === null ? null : new \DateTimeImmutable($debut . ':00'))
            ->setEndTime($fin === null ? null : new \DateTimeImmutable($fin . ':00'))
            ->setReason($reason);
        $em->persist($exception);
        $em->flush();
    }

    private function mettreEnVigueur(bool $enVigueur): void
    {
        $em = $this->em();
        $reglage = (new OpeningSetting())
            ->setEstablishment($this->siteActif())
            ->setEnforced($enVigueur);
        $em->persist($reglage);
        $em->flush();
    }

    private function siteActif(): Etablissement
    {
        $espace = $this->em()->getRepository(EspaceAcces::class)->find(Uuid::fromString($this->idEspaceAcces()));
        self::assertNotNull($espace);
        $etablissement = $espace->getEtablissement();
        self::assertNotNull($etablissement, 'L’espace d’accès du jeu de données doit porter un établissement.');

        return $etablissement;
    }

    /**
     * Un espace d'accès chez le VOISIN — « Patinoire B » de `SocleFixtures`, qui n'en portait pas.
     *
     * Réutilise un espace existant chez un autre établissement s'il y en a un ; sinon en crée un.
     * L'ordre compte : réutiliser d'abord évite d'accumuler un espace par exécution le jour où le
     * jeu de données en gagnera un.
     */
    private function creerEspaceChezLeVoisin(): string
    {
        $em = $this->em();
        $actif = $this->siteActif();

        /** @var list<EspaceAcces> $espaces */
        $espaces = $em->getRepository(EspaceAcces::class)->findAll();
        foreach ($espaces as $espace) {
            $etab = $espace->getEtablissement();
            if ($etab !== null && !$etab->getId()->equals($actif->getId())) {
                return (string) $espace->getId();
            }
        }

        $voisin = null;
        /** @var list<Etablissement> $etablissements */
        $etablissements = $em->getRepository(Etablissement::class)->findAll();
        foreach ($etablissements as $candidat) {
            if (!$candidat->getId()->equals($actif->getId())) {
                $voisin = $candidat;
                break;
            }
        }
        self::assertNotNull($voisin, 'Le jeu de données doit porter au moins deux établissements.');

        // `EspaceAcces` porte un `Espace` DE SOCLE, non nul en base : un espace de contrôle
        // d'accès est la déclinaison technique d'un espace de l'organisation, pas une chose à part.
        $espaceSocle = (new Espace())->setNom('Hall du voisin')->setType('hall')->setEtablissement($voisin);
        $em->persist($espaceSocle);

        $espace = (new EspaceAcces())
            ->setLibelle('Espace du voisin')
            ->setEspaceSocle($espaceSocle)
            ->setEtablissement($voisin);
        $em->persist($espace);
        $em->flush();

        return (string) $espace->getId();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
