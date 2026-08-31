<?php

declare(strict_types=1);

namespace App\Tests\Musee;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Musee\DataFixtures\MuseeFixtures;
use App\Musee\Entity\Exposition;
use App\Musee\Entity\Guide;
use App\Musee\Entity\PartenaireOTA;
use App\Musee\Entity\Salle;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Securite\Service\ContexteEtablissement;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API de la verticale Musée : schéma recréé et fixtures socle + offre + compta +
 * vente + CRM + SEPA + réservation + musée rechargées avant chaque test.
 */
abstract class MuseeApiTestCase extends ApiTestCase
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
            CrmFixtures::class, SepaFixtures::class, AccesFixtures::class, ReservationFixtures::class,
            MuseeFixtures::class,
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

    protected function idExposition(): string
    {
        return (string) $this->entite(Exposition::class, [])->getId();
    }

    protected function idSalle(): string
    {
        return (string) $this->entite(Salle::class, [])->getId();
    }

    protected function idGuide(): string
    {
        return (string) $this->entite(Guide::class, [])->getId();
    }

    protected function idPartenaireOTA(): string
    {
        return (string) $this->entite(PartenaireOTA::class, [])->getId();
    }

    /** Créneau du matin de l'exposition démo (fixture, 10h-11h, capacité 30). */
    protected function idCreneauMatin(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $expo = $em->getRepository(Exposition::class)->findOneBy([]);
        self::assertInstanceOf(Exposition::class, $expo);
        $ressourceEntree = $expo->getRessourceEntree();
        self::assertNotNull($ressourceEntree, 'Exposition démo sans ressource d\'entrée.');

        /** @var list<Creneau> $creneaux */
        $creneaux = $em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.ressource = :ressource')
            ->setParameter('ressource', $ressourceEntree->getId(), 'uuid')
            ->orderBy('c.debut', 'ASC')->setMaxResults(1)->getQuery()->getResult();
        self::assertNotEmpty($creneaux, 'Aucun créneau musée en fixture.');

        return (string) $creneaux[0]->getId();
    }

    /** Créneau de l'après-midi de l'exposition démo (fixture, 14h-15h, capacité 30). */
    protected function idCreneauApresMidi(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $expo = $em->getRepository(Exposition::class)->findOneBy([]);
        self::assertInstanceOf(Exposition::class, $expo);
        $ressourceEntree = $expo->getRessourceEntree();
        self::assertNotNull($ressourceEntree, 'Exposition démo sans ressource d\'entrée.');

        /** @var list<Creneau> $creneaux */
        $creneaux = $em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.ressource = :ressource')
            ->setParameter('ressource', $ressourceEntree->getId(), 'uuid')
            ->orderBy('c.debut', 'DESC')->setMaxResults(1)->getQuery()->getResult();
        self::assertNotEmpty($creneaux, 'Aucun créneau musée en fixture.');

        return (string) $creneaux[0]->getId();
    }

    /** Bénéficiaire payeur CRM de démonstration. */
    protected function idBeneficiairePayeur(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $client = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($client, 'Client payeur CRM introuvable.');
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        self::assertNotNull($beneficiaire, 'Bénéficiaire payeur introuvable.');

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
        $entite = $criteres === [] ? $em->getRepository($classe)->findOneBy([]) : $em->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite, sprintf('%s introuvable (%s).', $classe, json_encode($criteres)));

        return $entite;
    }
}
