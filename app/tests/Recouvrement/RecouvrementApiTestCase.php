<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
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
use App\Sport\Entity\EcheanceSepa;
use App\Tests\DdlHorsMapping;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Base des tests d'API du moteur de recouvrement partagé (`App\Recouvrement`). Le seul port
 * `RedevablePort` actuellement branché dans le socle est celui de Sport (fitness) : ces tests
 * réutilisent donc les fixtures Sport pour PRODUIRE un contrat/une échéance à faire transiter dans le
 * moteur générique, mais n'affirment que sur des ressources/champs génériques (`IncidentImpaye`,
 * `PolitiqueRecouvrement`, `TableauBordRecouvrement`) — jamais sur des champs propres à Sport. Voir
 * aussi `App\Tests\Recouvrement\Unit\RedevableRegistryTest` pour une preuve de généricité sans aucune
 * verticale réelle.
 */
abstract class RecouvrementApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        // FK_CHECKS désactivé le temps du drop/create (nombreuses tables inter-référencées) : évite les
        // échecs d'ordonnancement DROP/CREATE observés lors de l'introduction du schéma recouvrement_*.
        $connexion = $em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        DdlHorsMapping::appliquer($em);

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

    /** @return array{0: Client, 1: array<string, mixed>} client, entête auth+étab */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete];
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    /** Échéance à venir du contrat de démonstration (fitness), utilisée comme fait générateur du rejet. */
    protected function premiereEcheanceContratDemo(): EcheanceSepa
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $em->getRepository(AbonnementFitness::class)->findOneBy([], ['dateSouscription' => 'ASC']);
        self::assertNotNull($abonnement, 'Contrat de démonstration introuvable.');
        $echeance = $em->getRepository(EcheanceSepa::class)->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);
        self::assertNotNull($echeance, 'Échéance de démonstration introuvable.');

        return $echeance;
    }

    protected function politiqueDemo(): PolitiqueRecouvrement
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $politique = $em->getRepository(PolitiqueRecouvrement::class)->findOneBy(['etablissement' => $etab]);
        self::assertNotNull($politique, 'Politique de recouvrement de démonstration introuvable.');

        return $politique;
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
