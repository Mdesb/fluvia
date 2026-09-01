<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Socle des essais de reprise initiale.
 *
 * Les permissions `import.*` sont créées ici plutôt que dans une fixture : le module n'a pas de
 * jeu de démonstration — une reprise ne se démontre pas, elle se fait une fois, avec le fichier
 * d'un vrai client.
 */
abstract class ImportApiTestCase extends SocleApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        self::assertInstanceOf(Role::class, $role, 'Le rôle administrateur du socle est attendu.');

        foreach (['read', 'manage'] as $action) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'import', 'action' => $action])
                ?? (new Permission())->setModule('import')->setAction($action);
            $em->persist($permission);
            $role->addPermission($permission);
        }
        $em->flush();

        self::ensureKernelShutdown();
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>, 2: string} */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** Un CSV de reprise de clients, écrit comme un tableur français l'exporte. */
    protected function csv(string ...$lignes): string
    {
        return implode("\n", array_merge(
            ['externalRef;type;nom;prenom;raisonSociale;email;telephone;dateNaissance'],
            $lignes,
        ));
    }

    protected function etablissementA(): Etablissement
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);

        return $etab;
    }
}
