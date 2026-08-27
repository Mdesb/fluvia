<?php

declare(strict_types=1);

namespace App\Tests\Sport;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\DataFixtures\RecouvrementFixtures;
use App\Recouvrement\Entity\PolitiqueRecouvrement;
use App\Securite\Service\ContexteEtablissement;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Sport\DataFixtures\SportFixtures;
use App\Sport\Entity\AbonnementFitness;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API de la verticale Sport/Fitness : schéma recréé et fixtures socle + offre +
 * compta (ConfigCreancierSepa régie sur A, requis par le recâblage SEPA partagé) + accès + CRM +
 * SEPA (config créancier) + sport rechargées avant chaque test.
 */
abstract class SportApiTestCase extends ApiTestCase
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
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, AccesFixtures::class,
            CrmFixtures::class, SepaFixtures::class, RecouvrementFixtures::class, SportFixtures::class,
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

    protected function idEspaceAcces(): string
    {
        return (string) $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE])->getId();
    }

    protected function abonnementDemo(): AbonnementFitness
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $em->getRepository(AbonnementFitness::class)->findOneBy([], ['dateSouscription' => 'ASC']);
        self::assertNotNull($abonnement, 'Abonnement fitness de démonstration introuvable.');

        return $abonnement;
    }

    protected function idAbonnementDemo(): string
    {
        return (string) $this->abonnementDemo()->getId();
    }

    protected function idDroitAccesDemo(): string
    {
        return (string) $this->entite(DroitAcces::class, [])->getId();
    }

    protected function idPolitiqueDemo(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $politique = $em->getRepository(PolitiqueRecouvrement::class)->findOneBy(['etablissement' => $etab]);
        self::assertNotNull($politique, 'Politique de recouvrement de démonstration introuvable.');

        return (string) $politique->getId();
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
