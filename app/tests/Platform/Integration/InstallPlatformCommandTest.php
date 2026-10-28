<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Platform\Command\InstallPlatformCommand;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Subscription\Service\ProvisioningService;
use App\Tests\DdlHorsMapping;
use App\Tests\SchemaDuHarnais;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `platform:install` sur une base VIERGE — sans `SocleFixtures`, c'est tout l'objet.
 *
 * Le harnais bâtit le schéma depuis le mapping, pas depuis les migrations : le rôle modèle que la
 * migration `Version20260824233000` pose en production est donc recréé ici, à l'identique
 * (`*.*` + `securite.gerer`).
 */
final class InstallPlatformCommandTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const ADMIN = 'premier.admin@exemple.test';

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        SchemaDuHarnais::reinitialiser($em);
        DdlHorsMapping::appliquer($em);
        $this->roleTemplate();
        self::ensureKernelShutdown();
    }

    public function testPremierLancementCreeLEditeurEtUnAdministrateurInvite(): void
    {
        $tester = $this->install(['--admin-email' => self::ADMIN]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $editor = $this->editor();
        self::assertStringContainsString('EDITOR_TENANT_ID='.$editor->getId()->toRfc4122(), $tester->getDisplay());
        self::assertMatchesRegularExpression('#/activation\?jeton=[0-9a-f]{64}#', $tester->getDisplay());

        $admin = $this->admin();
        self::assertSame(StatutUtilisateur::Invite, $admin->getStatut());
        self::assertFalse($admin->isPlateforme(), 'L’administrateur de l’éditeur ne doit pas voir tous les établissements.');
        self::assertCount(1, $this->em()->getRepository(Affectation::class)->findBy(['utilisateur' => $admin, 'etablissement' => $editor]));
    }

    /** Le cas qui démasque : relancer. Rien ne doit être créé une seconde fois, ni lien réémis. */
    public function testSecondLancementNeCreeRien(): void
    {
        $this->install(['--admin-email' => self::ADMIN]);
        $avant = $this->counts();

        $tester = $this->install(['--admin-email' => self::ADMIN]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($avant, $this->counts());
        self::assertStringNotContainsString('/activation?jeton=', $tester->getDisplay());
        self::assertStringContainsString('existait déjà', $tester->getDisplay());
    }

    public function testNouveauLienRemplaceLAncien(): void
    {
        $premier = $this->lien($this->install(['--admin-email' => self::ADMIN])->getDisplay());
        $second = $this->lien($this->install(['--admin-email' => self::ADMIN, '--new-link' => true])->getDisplay());

        self::assertNotSame($premier, $second);
        self::assertSame(hash('sha256', $second), $this->admin()->getJetonInvitation());
    }

    /** Lancée sur une base qui a déjà des clients (la préproduction), elle refuse et n'écrit rien. */
    public function testRefuseUneBaseQuiADejaDesEtablissements(): void
    {
        $groupe = (new Groupe())->setNom('Client existant');
        $region = (new Region())->setNom('Client existant')->setGroupe($groupe);
        $etab = (new Etablissement())->setNom('Piscine existante')->setRegion($region)->setActif(true);
        $em = $this->em();
        $em->persist($groupe);
        $em->persist($region);
        $em->persist($etab);
        $em->flush();

        $tester = $this->install(['--admin-email' => self::ADMIN]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $this->em()->getRepository(Etablissement::class)->findAll());
        self::assertNull($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::ADMIN]));
    }

    public function testEchoueSansLeRoleModele(): void
    {
        $em = $this->em();
        foreach ($em->getRepository(Role::class)->findAll() as $role) {
            $em->remove($role);
        }
        $em->flush();

        $tester = $this->install(['--admin-email' => self::ADMIN]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->em()->getRepository(Etablissement::class)->findAll());
    }

    /**
     * Le lien imprimé active réellement le compte, et le compte activé a les droits de l'éditeur —
     * sur l'éditeur seulement.
     */
    public function testLeLienActiveUnCompteQuiAdministreLEditeur(): void
    {
        $lien = $this->lien($this->install(['--admin-email' => self::ADMIN])->getDisplay());
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('POST', '/utilisateurs/activation', [
            'json' => ['jeton' => $lien, 'motDePasse' => 'Activation-'.bin2hex(random_bytes(8)).'!9A'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $admin = $this->admin();
        self::assertSame(StatutUtilisateur::Actif, $admin->getStatut());

        /** @var CalculateurDroits $droits */
        $droits = static::getContainer()->get(CalculateurDroits::class);
        $codes = $droits->codesEffectifs($admin, $this->editor()->getId());
        self::assertTrue($droits->autorise($codes, 'editor', 'read_billing'));
        self::assertTrue($droits->autorise($codes, 'securite', 'gerer'));
    }

    /** @param array<string, mixed> $options */
    private function install(array $options): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('platform:install'));
        $tester->execute($options);
        $this->em()->clear();

        return $tester;
    }

    private function lien(string $display): string
    {
        self::assertSame(1, preg_match('#/activation\?jeton=([0-9a-f]{64})#', $display, $m), $display);

        return $m[1];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $em = $this->em();

        return [
            'etablissements' => \count($em->getRepository(Etablissement::class)->findAll()),
            'roles' => \count($em->getRepository(Role::class)->findAll()),
            'utilisateurs' => \count($em->getRepository(Utilisateur::class)->findAll()),
            'affectations' => \count($em->getRepository(Affectation::class)->findAll()),
        ];
    }

    private function editor(): Etablissement
    {
        $editor = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => InstallPlatformCommand::DEFAULT_EDITOR_NAME]);
        self::assertInstanceOf(Etablissement::class, $editor);

        return $editor;
    }

    private function admin(): Utilisateur
    {
        $this->em()->clear();
        $admin = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::ADMIN]);
        self::assertInstanceOf(Utilisateur::class, $admin);

        return $admin;
    }

    private function roleTemplate(): void
    {
        $em = $this->em();
        $role = (new Role())->setNom(ProvisioningService::ADMIN_ROLE_TEMPLATE)->setEstModele(true);
        foreach ([['*', '*'], ['securite', 'gerer']] as [$module, $action]) {
            $permission = (new Permission())->setModule($module)->setAction($action);
            $em->persist($permission);
            $role->addPermission($permission);
        }
        $em->persist($role);
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
