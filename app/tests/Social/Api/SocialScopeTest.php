<?php

declare(strict_types=1);

namespace App\Tests\Social\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Social\Entity\SocialAccount;
use App\Tests\Social\SocialApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cloisonnement du module social (D3/D8) — même patron que `App\Tests\Ocr\Api\CloisonnementOcrTest`.
 *
 * Un utilisateur affecté au seul établissement A, **muni des permissions sociales**, ne voit ni ne
 * modifie le compte de l'établissement B. La permission ouvre la ressource ; elle n'ouvre pas le
 * périmètre. C'est exactement la confusion qui a produit les seize IDOR trouvés dans ce dépôt.
 *
 * Échec en 404 et jamais 403 : un 403 dirait « ce compte existe, mais pas chez toi », ce qui suffirait
 * à énumérer les comptes sociaux des autres établissements.
 */
final class SocialScopeTest extends SocialApiTestCase
{
    public function testUtilisateurDeANeVoitPasLeCompteDeB(): void
    {
        [$email, $motDePasse] = $this->creerUtilisateurSocialSurA();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $compteB = $this->entite(SocialAccount::class, ['establishment' => $etabB]);

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $client->request('GET', '/api/social_accounts/' . $compteB->getId(), $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        $client->request('GET', '/api/social_accounts', $entete);
        self::assertResponseIsSuccessful();
        $corps = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString((string) $compteB->getId(), $corps);
        self::assertStringNotContainsString($compteB->getHandle(), $corps);
    }

    public function testUtilisateurDeANePeutPasPatcherLeCompteDeB(): void
    {
        [$email, $motDePasse] = $this->creerUtilisateurSocialSurA();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $compteB = $this->entite(SocialAccount::class, ['establishment' => $etabB]);
        $chiffreAvant = $compteB->getAccessTokenEncrypted();

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $client->request('PATCH', '/api/social_accounts/' . $compteB->getId(), [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['accessTokenPlain' => 'jeton-substitue-par-un-tiers'],
        ] + $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        // Substituer le jeton d un autre établissement serait publier en son nom : on vérifie que rien
        // n a bougé en base, pas seulement que la réponse est un 404.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $apres = $em->getRepository(SocialAccount::class)->find($compteB->getId());
        self::assertNotNull($apres);
        self::assertSame($chiffreAvant, $apres->getAccessTokenEncrypted());
    }

    /** @return array{0: string, 1: string} email, mot de passe d un utilisateur social.* affecté sur A uniquement */
    private function creerUtilisateurSocialSurA(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        $permRead = $em->getRepository(Permission::class)->findOneBy(['module' => 'social', 'action' => 'read_account']);
        $permManage = $em->getRepository(Permission::class)->findOneBy(['module' => 'social', 'action' => 'manage_account']);
        self::assertNotNull($permRead);
        self::assertNotNull($permManage);

        $role = (new Role())->setNom('Gestionnaire social (scope A, test cloisonnement)');
        $role->addPermission($permRead)->addPermission($permManage);
        $em->persist($role);

        $email = 'gestionnaire.social.scope-a@itcotation.com';
        $motDePasse = 'GestionnaireSocial#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Gestionnaire Social Scope A')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etabA));

        $em->flush();

        return [$email, $motDePasse];
    }
}
