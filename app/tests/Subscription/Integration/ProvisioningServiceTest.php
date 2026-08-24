<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;
use App\Platform\Module\ModuleAccess;
use App\Platform\Module\ModuleRegistry;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\ProvisioningRequest;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\ProvisioningStatus;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Service\DemoConfiguration;
use App\Subscription\Service\ProvisioningService;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ED-3 — CA-1 et CA-2 : un client qui paie obtient sa plateforme, et il ne l'obtient qu'une fois.
 *
 * Test d'intégration et non unitaire, délibérément. Ce que ce lot doit prouver n'est pas que le code
 * appelle les bonnes méthodes — c'est qu'une contrainte d'unicité **en base** empêche le second
 * webhook de créer un second établissement (RG-ED-05). Contre un double, l'idempotence serait vraie
 * par construction et fausse en production.
 */
final class ProvisioningServiceTest extends SocleApiTestCase
{
    private const COMPRISE = 'controle_acces';
    private const OPTION = 'reservation';
    private const NON_SOUSCRITE = 'no_show';

    private const EMAIL_PROSPECT = 'prospect@exemple.test';

    /** CA-1 — établissement créé, administrateur invitable, et seuls les modules souscrits exposés. */
    public function testCa1UnClientPayantObtientSonEtablissementSonAdministrateurEtSesModules(): void
    {
        $this->roleTemplate();
        $subscription = $this->subscription($this->prospect());

        $outcome = $this->service()->provision($subscription, $this->at());

        self::assertSame(ProvisioningStatus::Completed, $outcome->request->getStatus());
        self::assertTrue($outcome->isFirstRun());

        $establishment = $outcome->request->getEstablishment();
        self::assertInstanceOf(Etablissement::class, $establishment);

        $administrator = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL_PROSPECT]);
        self::assertInstanceOf(Utilisateur::class, $administrator);

        // Invité, pas actif : aucun mot de passe n'a été choisi à la place du client.
        self::assertSame(StatutUtilisateur::Invite, $administrator->getStatut());

        $access = $this->moduleAccess();
        self::assertTrue($access->hasModule($establishment, self::COMPRISE), 'la capacité comprise dans la formule doit être exposée');
        self::assertTrue($access->hasModule($establishment, self::OPTION), 'l\'option souscrite doit être exposée');
        self::assertFalse($access->hasModule($establishment, self::NON_SOUSCRITE), 'une capacité non souscrite ne doit pas être exposée');
    }

    /**
     * CA-2 — le même `subscription.activated` rejoué trois fois ne provisionne qu'une fois.
     *
     * C'est le cas qui coûte de l'argent : un webhook bancaire se répète, et un provisioning qui le
     * supporte mal crée deux établissements et facture deux fois.
     */
    public function testCa2LeMemeEvenementRejoueTroisFoisNeCreeQuUnEtablissement(): void
    {
        $this->roleTemplate();
        $subscription = $this->subscription($this->prospect());

        $premier = $this->service()->provision($subscription, $this->at());
        $this->service()->provision($subscription, $this->at());
        $dernier = $this->service()->provision($subscription, $this->at());

        self::assertSame(
            $premier->request->getId()->toRfc4122(),
            $dernier->request->getId()->toRfc4122(),
            'les trois rejeux doivent porter sur la même demande',
        );
        self::assertSame(1, $this->countProvisioningRequests());
        self::assertSame(1, $this->countAdministrators());
        self::assertSame(3, $dernier->request->getAttempts(), 'les rejeux sont comptés, pas exécutés');

        // Un rejeu ne régénère aucun jeton : cela invaliderait celui qu'on vient d'envoyer au client.
        self::assertTrue($premier->isFirstRun());
        self::assertFalse($dernier->isFirstRun());
    }

    /**
     * Le client provisionné vit dans son propre groupe, pas dans celui de l'éditeur.
     *
     * Sa fiche CRM, elle, reste chez l'éditeur : c'est le commerce. Si l'établissement livré héritait
     * du même groupe, le cloisonnement (D3) rendrait les données du client atteignables depuis le
     * tenant éditeur — ce que RG-ED-01 exclut explicitement.
     */
    public function testLeClientProvisionneNestPasDansLarbreDeLediteur(): void
    {
        $this->roleTemplate();
        $prospect = $this->prospect();
        $subscription = $this->subscription($prospect);

        $establishment = $this->service()->provision($subscription, $this->at())->request->getEstablishment();
        self::assertInstanceOf(Etablissement::class, $establishment);

        $groupeLivre = $establishment->getRegion()?->getGroupe();
        $groupeEditeur = $this->editeur()->getRegion()?->getGroupe();

        self::assertNotNull($groupeLivre);
        self::assertNotNull($groupeEditeur);
        self::assertNotSame(
            $groupeEditeur->getId()->toRfc4122(),
            $groupeLivre->getId()->toRfc4122(),
            'l\'établissement livré ne doit pas partager le groupe de l\'éditeur',
        );
    }

    /**
     * Deux abonnements pour la même adresse : refus tracé, rien de créé (spec §7).
     *
     * Rattacher le second établissement au compte existant donnerait à une personne un pied dans deux
     * périmètres. On préfère un échec lisible et un arbitrage humain.
     */
    public function testUneAdresseDejaUtiliseeEchoueSansRienCreer(): void
    {
        $this->roleTemplate();
        $etablissementsAvant = $this->countEstablishments();

        $this->em()->persist(
            (new Utilisateur())
                ->setEmail(self::EMAIL_PROSPECT)
                ->setNom('Compte préexistant')
                ->setMotDePasse('peu importe')
                ->setStatut(StatutUtilisateur::Actif),
        );
        $this->em()->flush();

        $outcome = $this->service()->provision($this->subscription($this->prospect()), $this->at());

        self::assertSame(ProvisioningStatus::Failed, $outcome->request->getStatus());
        self::assertNull($outcome->request->getEstablishment());
        self::assertStringContainsString('deux périmètres', (string) $outcome->request->getFailureReason());
        self::assertSame($etablissementsAvant, $this->countEstablishments(), 'aucun établissement ne doit avoir été créé');
    }

    /**
     * Rôle modèle absent : échec explicite plutôt que permissions inventées.
     *
     * Ce test fige une décision : le provisioning ne compose pas de politique d'habilitation. Le jour
     * où quelqu'un sera tenté d'ajouter « et sinon, on crée un rôle avec tous les droits », ce test
     * tombera — et c'est le but.
     */
    public function testSansRoleModeleLeProvisioningEchoueAuLieuDinventerDesDroits(): void
    {
        $etablissementsAvant = $this->countEstablishments();

        $outcome = $this->service()->provision($this->subscription($this->prospect()), $this->at());

        self::assertSame(ProvisioningStatus::Failed, $outcome->request->getStatus());
        self::assertStringContainsString(ProvisioningService::ADMIN_ROLE_TEMPLATE, (string) $outcome->request->getFailureReason());
        self::assertSame($etablissementsAvant, $this->countEstablishments());
        self::assertSame(0, $this->countAdministrators());
    }

    /**
     * Deux clients provisionnés à la suite — non-régression sur `uniq_role_nom`.
     *
     * Écrit après coup : le service donnait au rôle livré le nom du rôle modèle, ce qui passait au
     * premier client et échouait au second sur une contrainte d'unicité globale. Aucun test à un seul
     * client ne pouvait le voir, et en production le défaut serait apparu à la deuxième vente.
     */
    public function testDeuxClientsSuccessifsObtiennentChacunLeurRole(): void
    {
        $this->roleTemplate();

        $premier = $this->service()->provision(
            $this->subscription($this->prospect('premier@exemple.test', 'Camping des Pins')),
            $this->at(),
        );
        $second = $this->service()->provision(
            $this->subscription($this->prospect('second@exemple.test', 'Camping des Pins')),
            $this->at(),
        );

        self::assertSame(ProvisioningStatus::Completed, $premier->request->getStatus());
        self::assertSame(ProvisioningStatus::Completed, $second->request->getStatus(), 'le second client doit être provisionné lui aussi');

        // Même raison sociale des deux côtés : c'est le cas qui casse si le nom du rôle ne dépend que
        // du nom de l'établissement.
        self::assertSame(2, \count($this->em()->getRepository(Etablissement::class)->findBy(['nom' => 'Camping des Pins'])));
    }

    /**
     * Deux raisons sociales très longues qui ne diffèrent qu'en leur milieu.
     *
     * `sec_role.nom` fait 120 caractères et son unicité est globale : une troncature naïve par la fin
     * produirait deux noms identiques et ferait échouer le provisionnement d'un client qui a déjà
     * payé. Le cas est artificiel à l'œil, pas en base — une raison sociale complète avec forme
     * juridique et mentions dépasse couramment la centaine de caractères.
     */
    public function testDeuxRaisonsSocialesTresLonguesNeProduisentPasLeMemeNomDeRole(): void
    {
        $modele = $this->roleTemplate();

        $prefixe = str_repeat('Camping municipal des Pins ', 4); // ~108 caractères communs
        $suffixe = str_repeat(' et de la mer', 4);

        $premier = $this->service()->provision(
            $this->subscription($this->prospect('long1@exemple.test', $prefixe.'ALPHA'.$suffixe)),
            $this->at(),
        );
        $second = $this->service()->provision(
            $this->subscription($this->prospect('long2@exemple.test', $prefixe.'OMEGA'.$suffixe)),
            $this->at(),
        );

        self::assertSame(ProvisioningStatus::Completed, $premier->request->getStatus());
        self::assertSame(ProvisioningStatus::Completed, $second->request->getStatus());

        // Les rôles issus de *ce* modèle, et eux seuls : les fixtures du socle en portent d'autres,
        // non modèles, qui n'ont rien à voir avec le provisionnement.
        $noms = array_map(
            static fn (Role $role): string => $role->getNom(),
            $this->em()->getRepository(Role::class)->findBy(['roleModeleOrigine' => $modele]),
        );

        self::assertCount(2, $noms);
        self::assertCount(2, array_unique($noms), 'deux clients ne doivent jamais porter le même nom de rôle');

        foreach ($noms as $nom) {
            self::assertLessThanOrEqual(120, mb_strlen($nom), 'le nom doit tenir dans sec_role.nom');
        }
    }

    // ---------------------------------------------------------------- montage

    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-15 10:00:00');
    }

    private function service(): ProvisioningService
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var Fonctionnalites $features */
        $features = static::getContainer()->get(Fonctionnalites::class);

        // Aucun fournisseur d'instantané : ces tests portent sur le provisionnement, pas sur la
        // reprise de démo, qui a ses propres cas dans SubscriptionFunnelTest.
        return new ProvisioningService($this->em(), $hasher, $features, new DemoConfiguration([]));
    }

    /**
     * `ModuleAccess` est privé et inliné à la compilation : le conteneur ne le rend pas.
     *
     * On l'instancie plutôt que de le rendre public pour les besoins d'un test — modifier la
     * production pour arranger un test est le mauvais sens. Le registre vide suffit : `hasModule()`
     * ne consulte que `Fonctionnalites`, le registre ne sert qu'à `hasFeature()`.
     */
    private function moduleAccess(): ModuleAccess
    {
        /** @var Fonctionnalites $features */
        $features = static::getContainer()->get(Fonctionnalites::class);

        return new ModuleAccess(new ModuleRegistry([]), $features);
    }

    /** L'établissement de l'éditeur : c'est lui qui porte le CRM et les abonnements (D12, RG-ED-01). */
    private function editeur(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($etablissement instanceof Etablissement);

        return $etablissement;
    }

    /** La fiche CRM du prospect, chez l'éditeur. */
    private function prospect(string $email = self::EMAIL_PROSPECT, string $raisonSociale = 'Camping des Pins'): Client
    {
        $editeur = $this->editeur();

        $prospect = (new Client())
            ->setType(TypeClient::Morale)
            ->setGroupe($editeur->getRegion()?->getGroupe())
            ->setEtablissementCreation($editeur)
            ->setNom('Martin')
            ->setPrenom('Claire')
            ->setRaisonSociale($raisonSociale)
            ->setEmail($email);

        $this->em()->persist($prospect);
        $this->em()->flush();

        return $prospect;
    }

    private function subscription(Client $prospect): Subscription
    {
        $plan = (new Plan())
            ->setCode('plan_'.bin2hex(random_bytes(4)))
            ->setLabel('Formule de test')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities([self::COMPRISE]);
        $this->em()->persist($plan);

        $subscription = (new Subscription())
            ->setCustomerReference($prospect->getId()->toRfc4122())
            ->setPlan($plan);
        $subscription->addOption(self::OPTION, 1500, $this->at());
        $subscription->transitionTo(SubscriptionStatus::Active, $this->at());

        $this->em()->persist($subscription);
        $this->em()->flush();

        return $subscription;
    }

    /** Le rôle modèle que `Securite` doit fournir (B-3) — recréé ici pour ne pas dépendre des fixtures. */
    private function roleTemplate(): Role
    {
        $permission = (new Permission())->setModule('organisation')->setAction('administrer');
        $this->em()->persist($permission);

        $role = (new Role())
            ->setNom(ProvisioningService::ADMIN_ROLE_TEMPLATE)
            ->setEstModele(true);
        $role->addPermission($permission);

        $this->em()->persist($role);
        $this->em()->flush();

        return $role;
    }

    private function countProvisioningRequests(): int
    {
        return \count($this->em()->getRepository(ProvisioningRequest::class)->findAll());
    }

    private function countEstablishments(): int
    {
        return \count($this->em()->getRepository(Etablissement::class)->findAll());
    }

    /** Les comptes portant l'adresse du prospect — il ne doit jamais y en avoir deux. */
    private function countAdministrators(): int
    {
        return \count($this->em()->getRepository(Utilisateur::class)->findBy(['email' => self::EMAIL_PROSPECT]));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
