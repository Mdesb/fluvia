<?php

declare(strict_types=1);

namespace App\Tests\Acces;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Acces\Service\VersionSnapshotSequencer;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que voit une borne synchronisée en DELTA, et rien d'autre.
 *
 * ⚠ LA QUESTION POSÉE EST CELLE DE LA BORNE, PAS CELLE DE LA BASE. Vérifier que `statutProjection`
 * vaut `devalide` en base ne dit rien de la porte : une borne en mode dégradé ne lit pas la base,
 * elle lit `GET /terminal/snapshot?depuis=<curseur>`, et ce delta ne sert que les supports dont
 * `versionMaj` a avancé. Un droit suspendu sans que la version avance reste ouvert sur la borne
 * jusqu'au prochain snapshot complet — c'est exactement le défaut que ces tests tiennent.
 *
 * D'où la forme unique des assertions : prendre le curseur, modifier par le VRAI chemin, relire le
 * delta, et y chercher le support avec le bon statut.
 *
 * Le gestionnaire d'entités est relu à chaque usage : un appel HTTP du client de test redémarre le
 * noyau, et un `$em` gardé d'avant appartiendrait à un conteneur mort.
 */
trait SnapshotDeltaTrait
{
    protected function snapshotEm(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** Le curseur qu'une borne garderait après un snapshot complet. */
    protected function snapshotCursor(): int
    {
        $client = static::createClient();
        $reponse = $client->request('GET', '/api/terminal/snapshot', $this->snapshotHeaders() + ['query' => ['taille' => 2000]]);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        return (int) $reponse->toArray()['versionCourante'];
    }

    /** @return array<string, mixed>|null l'entrée du support dans le delta servi depuis `$since`, ou null s'il n'y est pas */
    protected function deltaEntry(int $since, string $identifier): ?array
    {
        $client = static::createClient();
        $query = $since > 0 ? ['depuis' => $since, 'taille' => 2000] : ['taille' => 2000];
        $reponse = $client->request('GET', '/api/terminal/snapshot', $this->snapshotHeaders() + ['query' => $query]);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        foreach ($reponse->toArray()['entrees'] as $entree) {
            if ($entree['identifiant'] === $identifier) {
                return $entree;
            }
        }

        return null;
    }

    /** L'entrée du support dans un snapshot COMPLET (sans `depuis`). */
    protected function fullSnapshotEntry(string $identifier): ?array
    {
        return $this->deltaEntry(0, $identifier);
    }

    /** @return array<string, mixed> */
    protected function snapshotHeaders(): array
    {
        return ['headers' => ['Authorization' => 'Bearer ' . AccesFixtures::TERMINAL_SECRET]];
    }

    protected function snapshotEtablissementA(): Etablissement
    {
        $etab = $this->snapshotEm()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);

        return $etab;
    }

    protected function snapshotFixtureSpace(): EspaceAcces
    {
        $espace = $this->snapshotEm()->getRepository(EspaceAcces::class)->findOneBy(['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        self::assertInstanceOf(EspaceAcces::class, $espace);

        return $espace;
    }

    /**
     * Un droit valide de l'établissement A, appairé à `$supports` supports, qui ouvre la zone de la
     * borne de démonstration (sauf `$spaces` explicite).
     *
     * Plusieurs supports : rien n'interdit qu'un même droit soit porté par un QR ET un badge (un seul
     * appairage actif PAR SUPPORT, pas par droit). C'est le cas qui démasque un chemin qui ne fait
     * avancer que « le » support qu'il a sous la main.
     *
     * @param list<EspaceAcces>|null $spaces
     *
     * @return array{0: string, 1: list<string>} id du droit, identifiants des supports
     */
    protected function createPairedRight(TypeDroitAcces $type, int $supports = 1, ?int $credit = null, ?array $spaces = null): array
    {
        $em = $this->snapshotEm();
        $etab = $this->snapshotEtablissementA();

        $droit = (new DroitAcces())
            ->setSourceType($type)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($etab)
            ->setCreditRestant($credit);
        foreach ($spaces ?? [$this->snapshotFixtureSpace()] as $space) {
            $droit->addAuthorisedSpace($space);
        }
        $em->persist($droit);

        /** @var VersionSnapshotSequencer $sequencer */
        $sequencer = static::getContainer()->get(VersionSnapshotSequencer::class);
        $identifiants = [];
        for ($i = 0; $i < $supports; ++$i) {
            $identifiant = 'DELTA-' . $i . '-' . substr((string) Uuid::v4(), 0, 8);
            $support = (new Support())->setIdentifiant($identifiant)->setType($i === 0 ? TypeSupport::Qr : TypeSupport::Rfid)->setEtablissement($etab);
            $em->persist($support);
            $appairage = (new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab);
            $em->persist($appairage);
            $support->setVersionMaj($sequencer->suivant());
            $identifiants[] = $identifiant;
        }
        $em->flush();

        return [(string) $droit->getId(), $identifiants];
    }

    /** Une réservation confirmée de l'établissement A, sur une ressource qui ouvre l'accès. */
    protected function createConfirmedReservation(): Reservation
    {
        $em = $this->snapshotEm();
        static::getContainer()->get(CrmFixtures::class)->load($em);
        $etab = $this->snapshotEtablissementA();
        $payeur = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertNotNull($beneficiaire);

        $ressource = (new Ressource())->setEtablissement($etab)->setCodeType('terrain')
            ->setLibelle('Terrain delta ' . uniqid())->setCapacitePropre(4)->setOuvreAcces(true);
        $em->persist($ressource);
        $debut = new \DateTimeImmutable('2026-12-01T10:00:00+00:00');
        $creneau = (new Creneau())->setRessource($ressource)->setDebut($debut)->setFin($debut->modify('+60 minutes'))
            ->setCapacite(4)->setEtablissement($etab)->setStatut(StatutCreneau::Planifie);
        $em->persist($creneau);
        $reservation = (new Reservation())->setCreneau($creneau)->setOrganisateur($beneficiaire)
            ->setEtablissement($etab)->setModeDecompte(ModeDecompteReservation::Gratuit)->setMontantDu('0.00')
            ->setStatut(StatutReservation::Confirmee);
        $em->persist($reservation);
        $em->flush();

        return $reservation;
    }

    /** @return array{0: Uuid, 1: Uuid, 2: string} badge, créneau, identifiant du support */

    protected function findRight(string $id): DroitAcces
    {
        $droit = $this->snapshotEm()->getRepository(DroitAcces::class)->find(Uuid::fromString($id));
        self::assertInstanceOf(DroitAcces::class, $droit);

        return $droit;
    }

    /** Une entrée PRÉSENTE au delta, avec le message qui dit quel chemin a oublié la version. */
    protected function assertInDelta(int $since, string $identifier, string $path): array
    {
        $entree = $this->deltaEntry($since, $identifier);
        self::assertNotNull($entree, sprintf(
            '%s : le support %s n\'apparaît pas au delta depuis %d — une borne synchronisée en delta garde l\'état d\'avant.',
            $path,
            $identifier,
            $since,
        ));

        return $entree;
    }
}
