<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutTerminal;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cloisonnement multi-entités des ressources L3 (RG-SOCLE-05) : un utilisateur sans affectation sur
 * l'établissement d'un espace n'y a aucun accès (données absentes de ses lectures).
 */
final class CloisonnementTest extends AccesApiTestCase
{
    public function testLecteurSansAffectationSurEtabBNeVoitPasSesEspacesAcces(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);

        $espaceSocleB = (new Espace())->setNom('Espace B privé')->setEtablissement($etabB)->setType('bassin');
        $em->persist($espaceSocleB);
        $espaceAccesB = (new EspaceAcces())->setLibelle('Espace Accès B privé')->setEspaceSocle($espaceSocleB)->setSeuilFmi(10);
        $em->persist($espaceAccesB);
        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        // Le lecteur (affecté uniquement sur A) ne doit voir aucun espace de B, même avec acces.lire.
        $client->request('GET', '/api/espace_acces', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        foreach ($membres as $item) {
            self::assertNotSame('Espace Accès B privé', $item['libelle']);
        }
    }

    /**
     * Cloisonnement `Terminal` (RG-SOCLE-05) : un gestionnaire `acces.gerer` affecté uniquement sur A
     * ne doit pouvoir ni révoquer ni faire tourner le jeton d'un `Terminal` rattaché à B — l'admin
     * socle par défaut (`SocleFixtures`) étant affecté sur A **et** B, ce test construit un utilisateur
     * dédié scopé à A uniquement pour isoler correctement le comportement attendu.
     */
    public function testGestionnaireScopeANePeutPasAgirSurUnTerminalDeB(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);

        $terminalB = (new Terminal())
            ->setNom('ITBOX Cloisonnement B')
            ->setItboxRef('ITBOX-CLOISON-B')
            ->setEtablissement($etabB)
            ->setStatut(StatutTerminal::Actif);
        $em->persist($terminalB);
        $em->flush();

        [$email, $motDePasse] = $this->creerGestionnaireAccesScopeSurA();

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        // Filtré par `PerimetreAccesExtension` (RG-SOCLE-05, `$data` absent du périmètre de lecture) :
        // `RevoquerTerminalProcessor`/`RotationJetonTerminalProcessor` doivent renvoyer un 404 propre
        // (durcissement, cf. leur `assert()` remplacé par une garde explicite) plutôt qu'un 500.
        $revocation = $client->request('POST', '/api/acces/terminaux/' . $terminalB->getId() . '/revoquer', $entete);
        self::assertSame(404, $revocation->getStatusCode(), (string) $revocation->getContent(false));

        $rotation = $client->request('POST', '/api/acces/terminaux/' . $terminalB->getId() . '/jetons', $entete);
        self::assertSame(404, $rotation->getStatusCode(), (string) $rotation->getContent(false));

        $em->clear();
        $terminalApres = $em->getRepository(Terminal::class)->find($terminalB->getId());
        self::assertNotNull($terminalApres);
        self::assertSame('actif', $terminalApres->getStatut()->value, 'Le Terminal de B doit rester intact (aucune action cross-établissement).');
    }

    /** @return array{0: string, 1: string} email, mot de passe d'un utilisateur acces.gerer affecté sur A uniquement */
    private function creerGestionnaireAccesScopeSurA(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'acces', 'action' => 'gerer']);
        if (!$permission instanceof Permission) {
            $permission = (new Permission())->setModule('acces')->setAction('gerer');
            $em->persist($permission);
        }

        $role = (new Role())->setNom('Gestionnaire Accès (scope A, test cloisonnement)');
        $role->addPermission($permission);
        $em->persist($role);

        $email = 'gestionnaire.acces.scope-a@itcotation.com';
        $motDePasse = 'GestionnaireAcces#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Gestionnaire Accès Scope A')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etabA));

        $em->flush();

        return [$email, $motDePasse];
    }
}
