<?php

declare(strict_types=1);

namespace App\Tests\Boutique;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\Vitrine;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API M3 Boutique (L8) : schéma recréé et fixtures socle + offre + compta + vente +
 * CRM + SEPA + accès + réservation + boutique rechargées avant chaque test.
 */
abstract class BoutiqueApiTestCase extends ApiTestCase
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

        // Isolation entre tests : vide le pool cache.app (utilisé par le limiter anti-bruteforce
        // d'identification), sinon le compteur d'échecs d'un test fuit vers le suivant (même IP+email).
        $cacheApp = $container->get('cache.app');
        if (\is_object($cacheApp) && method_exists($cacheApp, 'clear')) {
            $cacheApp->clear();
        }

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, AccesFixtures::class, ReservationFixtures::class,
            BoutiqueFixtures::class,
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

    /** @return array{0: Client, 1: array<string, mixed>} */
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

    protected function idVitrineA(): string
    {
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        return (string) $this->entite(Vitrine::class, ['etablissement' => $etabA])->getId();
    }

    protected function idVitrineB(): string
    {
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);

        return (string) $this->entite(Vitrine::class, ['etablissement' => $etabB])->getId();
    }

    /** Ouvre un panier invité sur la vitrine A, renvoie [client, id panier, jeton clair]. */
    protected function ouvrirPanierInviteA(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $reponse = $client->request('POST', '/api/boutique/paniers', [
            'json' => ['vitrine' => $this->idVitrineA()],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        $id = isset($donnees['id']) ? (string) $donnees['id'] : basename((string) $donnees['@id']);

        return [$client, $id, (string) $donnees['jetonSession']];
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

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
