<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\DroitAcces;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CA-5 (RG-ACC3-06, plan-acc3.md §0/§3/§9) — volet cloisonnement contrôlé par ce lot : le `DroitAcces`
 * projeté depuis une réservation porte l'établissement de la réservation (a), et un agent scopé sur un
 * autre établissement ne peut pas le lire, via le cloisonnement générique `PerimetreAccesExtension`
 * (b), inchangé par ce lot.
 *
 * Le second volet de CA-5 (« ni l'utiliser comme cible d'un appairage ») dépend d'un durcissement de
 * `App\Acces\State\AppairageProcessor` (proposition T7 du plan, non tranchée par l'intégrateur à ce
 * jour — gap pré-existant partagé par tous les `TypeDroitAcces`, signalé hors-lot ACC-3) : volontairement
 * non testé ici, cf. commentaire dans le test ci-dessous.
 */
final class CloisonnementReservationDroitTest extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, CrmFixtures::class, AccesFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        self::ensureKernelShutdown();
    }

    public function testAgentScopeBNePeutPasLireLeDroitProjeteSurA(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        // Réservation confirmée sur A, ressource ouvreAcces=true (construite directement, sans passer
        // par l'API réservation — non nécessaire pour ce test de cloisonnement, cf. CA-1 déjà couvert
        // par ProjectionAccesTest) : projection réelle via le handler du conteneur (RG-ACC3-01/02).
        $ressource = (new Ressource())->setEtablissement($etabA)->setCodeType('terrain')
            ->setLibelle('Terrain Cloisonnement ACC-3')->setCapacitePropre(4)->setOuvreAcces(true);
        $em->persist($ressource);

        $debut = new \DateTimeImmutable('2026-11-12T09:00:00+00:00');
        $creneau = (new Creneau())->setRessource($ressource)->setDebut($debut)->setFin($debut->modify('+60 minutes'))
            ->setCapacite(4)->setEtablissement($etabA)->setStatut(StatutCreneau::Planifie);
        $em->persist($creneau);

        $payeur = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(CrmClient::class, $payeur);
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertInstanceOf(Beneficiaire::class, $beneficiaire);

        $reservation = (new Reservation())->setCreneau($creneau)->setOrganisateur($beneficiaire)
            ->setEtablissement($etabA)->setModeDecompte(ModeDecompteReservation::Gratuit)->setMontantDu('0.00')
            ->setStatut(StatutReservation::Confirmee);
        $em->persist($reservation);
        $em->flush();

        /** @var ProjectionAccesReservationHandler $handler */
        $handler = static::getContainer()->get(ProjectionAccesReservationHandler::class);
        $projection = $handler->projeterSiApplicable($reservation);
        self::assertNotNull($projection);
        $droitId = $projection->getDroitAccesRef();
        self::assertNotNull($droitId);

        $droit = $em->getRepository(DroitAcces::class)->find($droitId);
        self::assertInstanceOf(DroitAcces::class, $droit);
        self::assertNotNull($droit->getEtablissement());
        self::assertTrue(
            $droit->getEtablissement()->getId()->equals($etabA->getId()),
            'RG-ACC3-06 (a) : DroitAcces.etablissement = Reservation.etablissement.',
        );

        // (b) Un agent affecté uniquement sur B ne doit pas pouvoir lire ce droit projeté sur A.
        [$email, $motDePasse, $idB] = $this->creerLecteurAccesScopeSurB();

        $client = static::createClient();
        $token = $client->request('POST', '/auth', ['json' => ['email' => $email, 'motDePasse' => $motDePasse]])->toArray()['token'];
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]];

        $client->request('GET', '/api/droit_acces/' . $droitId, $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        // gap AppairageProcessor pre-existant, signale hors-lot ACC-3 : le volet « ni cible d'un
        // appairage » de CA-5 n'est volontairement pas vérifié ici (App\Acces\State\AppairageProcessor
        // résout le DroitAcces par EntityManager::find() brut, sans repasser par
        // PerimetreAccesExtension — cf. plan-acc3.md §0/§3/§9, tâche T7 proposée non tranchée par
        // l'intégrateur ; corriger AppairageProcessor est hors périmètre strict de ce lot).
    }

    /** @return array{0: string, 1: string, 2: string} email, mot de passe, id établissement B */
    private function creerLecteurAccesScopeSurB(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);

        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'acces', 'action' => 'lire']);
        self::assertNotNull($permission, 'Permission acces.lire attendue (AccesFixtures).');

        $role = (new Role())->setNom('Lecteur Accès (scope B, test cloisonnement ACC-3)');
        $role->addPermission($permission);
        $em->persist($role);

        $email = 'lecteur.acces.scope-b.acc3@itcotation.com';
        $motDePasse = 'LecteurAccesB#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Lecteur Accès Scope B (ACC-3)')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etabB));

        $em->flush();

        return [$email, $motDePasse, (string) $etabB->getId()];
    }
}
