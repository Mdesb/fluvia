<?php

declare(strict_types=1);

namespace App\Tests\Reservation;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Enum\StatutReservation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API du module socle M5 (`App\Reservation`) : schéma recréé et fixtures socle +
 * offre + compta + CRM + SEPA + réservation rechargées avant chaque test.
 */
abstract class ReservationApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, ReservationFixtures::class,
        ] as $classe) {
            $fixture = $container->get($classe);
            $fixture->load($em);
        }

        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement A */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} */
    protected function gestionnaireSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, ReservationFixtures::GESTIONNAIRE_EMAIL, ReservationFixtures::GESTIONNAIRE_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} */
    protected function agentSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, ReservationFixtures::AGENT_EMAIL, ReservationFixtures::AGENT_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} */
    protected function operateurSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, ReservationFixtures::OPERATEUR_EMAIL, ReservationFixtures::OPERATEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} */
    protected function clientSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, ReservationFixtures::CLIENT_EMAIL, ReservationFixtures::CLIENT_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function entetePatch(array $entete): array
    {
        $entete['headers'] = ($entete['headers'] ?? []) + ['Content-Type' => 'application/merge-patch+json'];

        return $entete;
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idRessource(string $libelle): string
    {
        return (string) $this->entite(Ressource::class, ['libelle' => $libelle])->getId();
    }

    protected function idActivite(string $libelle): string
    {
        return (string) $this->entite(Activite::class, ['libelle' => $libelle])->getId();
    }

    /** Beneficiaire lié au client CRM payeur de démonstration (organisateur de démonstration). */
    protected function idBeneficiairePayeur(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($payeur, 'Client payeur de démonstration introuvable.');
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertNotNull($beneficiaire, 'Bénéficiaire payeur de démonstration introuvable.');

        return (string) $beneficiaire->getId();
    }

    /**
     * Ouvre une session de caisse (agent), pour les scénarios de vente à l'unité / no-show
     * `vente_differee_agent`.
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function ouvrirSession(Client $client, array $entete, string $fond = '50.00'): array
    {
        return $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
                'caisse' => '/api/caisses/' . $this->idCaisse(),
                'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => $fond,
            ],
        ])->toArray();
    }

    protected function idPointDeVente(): string
    {
        return (string) $this->entite(PointDeVente::class, ['libelle' => VenteFixtures::PDV_LIBELLE])->getId();
    }

    protected function idCaisse(): string
    {
        return (string) $this->entite(Caisse::class, ['libelle' => VenteFixtures::CAISSE_LIBELLE])->getId();
    }

    protected function idAdmin(): string
    {
        return (string) $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId();
    }

    /**
     * Début et fin (ISO 8601, UTC) d'un créneau d'une heure, à 10:00 dans `$jours` jours.
     *
     * ⚠ UNE DATE FIXE « LARGEMENT DANS LE FUTUR » FINIT PAR NE PLUS L'ÊTRE. Le délai franc se compare
     * à l'horloge réelle (`AnnulerReservationProcessor` : `new \DateTimeImmutable()`). Écrits les 16
     * et 19/08 avec des créneaux au 01/10 et au 08/10, deux tests « annulation dans le délai » sont
     * passés au rouge le 30/09 puis le 07/10 à 12:00 (Paris), sans qu'une ligne de code ait changé.
     * Relatif au lancement, l'écart au délai franc (24 h) ne dépend plus ni du jour ni de l'heure.
     *
     * @return array{0: string, 1: string}
     */
    protected static function creneauDans(int $jours): array
    {
        $debut = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('+%d days', $jours))->setTime(10, 0);

        return [$debut->format(\DATE_ATOM), $debut->modify('+1 hour')->format(\DATE_ATOM)];
    }

    /**
     * Une réservation d'un créneau déjà passé, au statut voulu, avec sa facturation d'absence si un
     * statut de facturation est donné. Posée en base : la bascule automatique est retirée (D95).
     *
     * @return array{0: string, 1: ?string} id de la réservation, id de la facturation
     */
    protected function pastBooking(string $etablissement, StatutReservation $statut, ?StatutFacturationNoShow $billing = null): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => $etablissement]);
        $ressource = (new Ressource())->setEtablissement($etab)->setCodeType('terrain')
            ->setLibelle('Terrain passé ' . bin2hex(random_bytes(4)))->setCapacitePropre(4);
        $creneau = (new Creneau())->setRessource($ressource)->setEtablissement($etab)->setCapacite(4)
            ->setDebut(new \DateTimeImmutable('-3 hours'))->setFin(new \DateTimeImmutable('-2 hours'));
        $reservation = (new Reservation())->setCreneau($creneau)->setEtablissement($etab)->setStatut($statut)
            ->setOrganisateur($em->find(Beneficiaire::class, $this->idBeneficiairePayeur()));
        $em->persist($ressource);
        $em->persist($creneau);
        $em->persist($reservation);

        $facturation = null;
        if ($billing !== null) {
            $regle = (new RegleAnnulation())->setEtablissement($etab)->setActif(false);
            $facturation = (new FacturationNoShow())->setReservation($reservation)->setRegleAppliquee($regle)
                ->setMontant('10.00')->setStatut($billing);
            $em->persist($regle);
            $em->persist($facturation);
        }
        $em->flush();

        return [(string) $reservation->getId(), $facturation === null ? null : (string) $facturation->getId()];
    }

    protected function idBeneficiaireParPrenom(string $prenom): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $client = $em->getRepository(CrmClient::class)->findOneBy(['prenom' => $prenom]);
        self::assertNotNull($client, sprintf('Client prénommé « %s » introuvable.', $prenom));
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        self::assertNotNull($beneficiaire, sprintf('Bénéficiaire prénommé « %s » introuvable.', $prenom));

        return (string) $beneficiaire->getId();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $classe
     * @param array<string, mixed> $criteres
     *
     * @return T
     */
    protected function entite(string $classe, array $criteres): object
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite, sprintf('%s introuvable (%s).', $classe, json_encode($criteres)));

        return $entite;
    }
}
