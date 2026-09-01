<?php

declare(strict_types=1);

namespace App\Tests\Import;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Import\DataFixtures\ImportFixtures;
use App\Import\Entity\ImportBatch;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\SchemaDuHarnais;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests d'API du lot I1 (`App\Import`) : schéma recréé + fixtures socle/import rechargées
 * avant chaque test (même patron que `App\Tests\Finance\TreasuryApiTestCase`).
 */
abstract class ImportApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        SchemaDuHarnais::reinitialiser($em);

        foreach ([SocleFixtures::class, ImportFixtures::class] as $classe) {
            $container->get($classe)->load($em);
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

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement B */
    protected function adminSurB(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]];

        return [$client, $entete, $idB];
    }

    /**
     * Utilisateur affecté **uniquement** sur l'établissement donné, avec les codes `import.<action>`
     * demandés — sert à reproduire l'IDOR cross-tenant (D8).
     *
     * @param list<string> $actions
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string}
     */
    protected function operateurImportSur(string $nomEtab, array $actions): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtab]);
        self::assertNotNull($etab, sprintf('Établissement « %s » introuvable.', $nomEtab));

        $suffixe = bin2hex(random_bytes(4));

        $role = (new Role())->setNom('Opérateur Import ' . $nomEtab . ' ' . $suffixe);
        foreach ($actions as $action) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'import', 'action' => $action]);
            self::assertNotNull($permission, sprintf('Permission import.%s introuvable (ImportFixtures).', $action));
            $role->addPermission($permission);
        }
        $em->persist($role);

        $email = 'operateur.import.' . $suffixe . '@itcotation.com';
        $motDePasse = 'Operateur#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Opérateur Import ' . $suffixe)->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $affectation = (new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etab);
        $em->persist($affectation);

        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => (string) $etab->getId()]];

        return [$client, $entete, (string) $etab->getId()];
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    /**
     * Dépose un fichier CSV (`POST /imports`) et renvoie la réponse décodée.
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function deposerImport(
        Client $client,
        array $entete,
        string $contenuCsv,
        string $type = 'customers',
        ?string $fileName = 'clients.csv',
    ): array {
        return $client->request('POST', '/api/imports', $entete + [
            'json' => [
                'type' => $type,
                'fileName' => $fileName,
                'mimeType' => 'text/csv',
                'content' => base64_encode($contenuCsv),
            ],
        ])->toArray(false);
    }

    /** Un fichier CSV `customers` 100 % valide, `$n` lignes, `externalRef` = `EXT-<préfixe>-<n>`. */
    protected function csvClientsValides(int $n, string $prefixe = 'EXT'): string
    {
        $lignes = ['externalRef;type;nom;prenom;email'];
        for ($i = 1; $i <= $n; ++$i) {
            $lignes[] = sprintf('%s-%d;physique;Nom%d;Prenom%d;client%d@test.fr', $prefixe, $i, $i, $i, $i);
        }

        return implode("\n", $lignes) . "\n";
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
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
        $entite = $this->em()->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite, sprintf('%s introuvable (%s).', $classe, json_encode($criteres)));

        return $entite;
    }

    protected function compterClients(): int
    {
        return (int) $this->em()->getRepository(CrmClient::class)->count([]);
    }

    protected function trouverLotParFileName(string $fileName): ImportBatch
    {
        return $this->entite(ImportBatch::class, ['fileName' => $fileName]);
    }
}
