<?php

declare(strict_types=1);

namespace App\Social\DataFixtures;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Social\Crypto\SocialTokenCipher;
use App\Social\Entity\SocialAccount;
use App\Social\Enum\SocialAccountStatus;
use App\Social\Enum\SocialNetwork;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Jeu de données du module `App\Social` (SOC-1) : permissions `social.read_account` /
 * `social.manage_account` accordées à l'administrateur socle, un compte Mastodon connecté sur
 * l'établissement A et un compte Bluesky connecté sur l'établissement B.
 *
 * Deux établissements et non un seul : c'est ce qui rend le test de cloisonnement possible. Un jeu de
 * données mono-établissement ne peut pas prouver qu'un compte d'autrui est invisible — il ne peut que
 * prouver que le sien est visible, ce qui n'a jamais rien démontré.
 *
 * Le jeton de démonstration est une chaîne inerte, chiffrée comme les vraies : la fixture prouve au
 * passage qu'un jeton stocké ne ressort jamais en clair de l'API.
 */
final class SocialFixtures extends Fixture implements DependentFixtureInterface
{
    /** Jeton inerte de démonstration — aucune valeur chez aucun réseau. */
    public const DEMO_ACCESS_TOKEN = 'demo-social-access-token-0000000000';

    public const MASTODON_HANDLE = '@piscine-a@mastodon.social';
    public const BLUESKY_HANDLE = 'piscine-b.bsky.social';

    public function __construct(
        private readonly SocialTokenCipher $cipher,
    ) {
    }

    /** @return list<class-string> */
    public function getDependencies(): array
    {
        return [SocleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $permRead = (new Permission())->setModule('social')->setAction('read_account');
        $permManage = (new Permission())->setModule('social')->setAction('manage_account');
        $manager->persist($permRead);
        $manager->persist($permManage);

        $roleAdmin = $manager->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        if ($roleAdmin instanceof Role) {
            $roleAdmin->addPermission($permRead)->addPermission($permManage);
        }

        $etabA = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabB = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        if (!$etabA instanceof Etablissement || !$etabB instanceof Etablissement) {
            $manager->flush();

            return;
        }

        $mastodonA = new SocialAccount();
        $mastodonA->setEstablishment($etabA)
            ->setNetwork(SocialNetwork::Mastodon)
            ->setHost('https://mastodon.social')
            ->setRemoteAccountId('109000000000000001')
            ->setHandle(self::MASTODON_HANDLE)
            ->setAccessTokenEncrypted($this->cipher->encrypt(self::DEMO_ACCESS_TOKEN))
            ->setStatus(SocialAccountStatus::Connected);
        $manager->persist($mastodonA);

        $blueskyB = new SocialAccount();
        $blueskyB->setEstablishment($etabB)
            ->setNetwork(SocialNetwork::Bluesky)
            ->setHost('https://bsky.social')
            ->setRemoteAccountId('did:plc:demo0000000000000000000b')
            ->setHandle(self::BLUESKY_HANDLE)
            ->setAccessTokenEncrypted($this->cipher->encrypt(self::DEMO_ACCESS_TOKEN))
            ->setStatus(SocialAccountStatus::Connected);
        $manager->persist($blueskyB);

        $manager->flush();
    }
}
