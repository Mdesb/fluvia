<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\DataFixtures\SocleFixtures;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Module\ModuleAccess;
use App\Platform\Module\ModuleRegistry;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationOutcome;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Subscription\Entity\ProvisioningRequest;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\ProvisioningStatus;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\EventListener\SendTrialConfirmationEmail;
use App\Subscription\Exception\ExpiredConfirmationLinkException;
use App\Subscription\Exception\UnknownConfirmationTokenException;
use App\Subscription\Service\DemoConfiguration;
use App\Subscription\Service\OfferCatalog;
use App\Subscription\Service\ProvisioningService;
use App\Subscription\Service\SubscriptionActivator;
use App\Subscription\Service\SubscriptionFunnel;
use App\Subscription\Service\SubscriptionMandates;
use App\Subscription\Command\IssueTrialConfirmationLinkCommand;
use App\Subscription\Service\TrialConfirmationLink;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ED-5 — l'essai gratuit de quatorze jours, de la demande a l'echeance.
 *
 * **Ce que ces tests cherchent, et que le tunnel payant n'avait pas a chercher.** Le tunnel SEPA a
 * une garde naturelle : un mandat signe. L'essai n'en a aucune — il est public, gratuit, et il cree
 * un etablissement REEL. Sa seule protection est la confirmation d'adresse. Les tests portent donc
 * autant sur ce qui doit se produire que sur ce qui ne doit **jamais** se produire : pas
 * d'etablissement avant le clic, pas de terme repousse par un second clic, pas de plateforme ouverte
 * par un lien perime.
 */
final class TrialFunnelTest extends SocleApiTestCase
{
    private const COMPRISE = 'controle_acces';
    private const OPTION = 'reservation';
    private const PLAN_CODE = 'formule_essentiel';
    private const EMAIL = 'piscine@exemple.test';
    private const IBAN = 'FR7630006000011234567890189';

    /** Le chemin nominal : demande, confirmation, plateforme ouverte pour quatorze jours. */
    public function testLaConfirmationDadresseOuvreLaPlateformePourQuatorzeJours(): void
    {
        $this->offre();
        $this->roleTemplate();

        $abonnement = $this->funnel()->openCart('Piscine du Lac', self::EMAIL, self::PLAN_CODE, [self::COMPRISE, self::OPTION], $this->at());
        $jeton = $this->demanderEtCapturerLeJeton($abonnement);

        // Rien n'existe encore : c'est le coeur de la garde.
        self::assertSame(SubscriptionStatus::Draft, $abonnement->getStatus(), 'la demande ne vend rien');
        self::assertNull($this->provisioningOf($abonnement), 'aucun etablissement avant le clic sur le lien');

        $confirme = $this->funnel()->confirmEmailAndStartTrial($jeton, $this->at());

        self::assertSame($abonnement->getId(), $confirme->getId());
        self::assertSame(SubscriptionStatus::Active, $confirme->getStatus());
        self::assertEquals($this->at()->modify('+14 days'), $confirme->getTrialEndsAt());
        self::assertTrue($confirme->isInTrial($this->at()));
        self::assertFalse($confirme->isInTrial($this->at()->modify('+15 days')));

        $demande = $this->provisioningOf($confirme);
        self::assertInstanceOf(ProvisioningRequest::class, $demande);
        self::assertSame(ProvisioningStatus::Completed, $demande->getStatus());

        $etablissement = $demande->getEstablishment();
        self::assertInstanceOf(Etablissement::class, $etablissement);
        self::assertSame('Piscine du Lac', $etablissement->getNom());

        // Un essai livre exactement ce qui a ete compose, pas la plateforme entiere.
        $acces = $this->moduleAccess();
        self::assertTrue($acces->hasModule($etablissement, self::COMPRISE));
        self::assertTrue($acces->hasModule($etablissement, self::OPTION));
        self::assertFalse($acces->hasModule($etablissement, 'no_show'));
    }

    /**
     * Le second clic ne recree rien et n'echoue pas.
     *
     * Les clients de messagerie pre-visitent les liens et les gens cliquent deux fois : un lien qui
     * repond « erreur » au deuxieme passage fait croire a un echec alors que tout a marche.
     */
    public function testUnSecondClicNeRecreeRienEtNechouePas(): void
    {
        $this->offre();
        $this->roleTemplate();

        $abonnement = $this->funnel()->openCart('Deux clics', self::EMAIL, self::PLAN_CODE, [self::COMPRISE], $this->at());
        $jeton = $this->demanderEtCapturerLeJeton($abonnement);

        $this->funnel()->confirmEmailAndStartTrial($jeton, $this->at());
        $etablissementsApresLePremierClic = $this->countEstablishments();
        $termeApresLePremierClic = $abonnement->getTrialEndsAt();

        $rejoue = $this->funnel()->confirmEmailAndStartTrial($jeton, $this->at()->modify('+2 hours'));

        self::assertSame($abonnement->getId(), $rejoue->getId());
        self::assertSame($etablissementsApresLePremierClic, $this->countEstablishments(), 'le second clic ne cree pas un second etablissement');
        self::assertEquals($termeApresLePremierClic, $rejoue->getTrialEndsAt(), 'le second clic ne repousse pas le terme de l essai');
    }

    /** Un jeton qui n'a jamais existe est refuse, et rien n'est cree. */
    public function testUnJetonInconnuEstRefuse(): void
    {
        $this->offre();
        $etablissementsAvant = $this->countEstablishments();

        $this->expectException(UnknownConfirmationTokenException::class);

        try {
            $this->funnel()->confirmEmailAndStartTrial(bin2hex(random_bytes(32)), $this->at());
        } finally {
            self::assertSame($etablissementsAvant, $this->countEstablishments());
        }
    }

    /** Passe soixante-douze heures, le lien ne vaut plus rien. */
    public function testUnLienExpireNouvrePasDePlateforme(): void
    {
        $this->offre();
        $this->roleTemplate();
        $etablissementsAvant = $this->countEstablishments();

        $abonnement = $this->funnel()->openCart('Trop tard', self::EMAIL, self::PLAN_CODE, [self::COMPRISE], $this->at());
        $jeton = $this->demanderEtCapturerLeJeton($abonnement);

        // `createdAt` est pose par le constructeur de l'entite : on lit ce qu'il a ecrit plutot que
        // de le supposer, et on se place juste au-dela de la fenetre.
        $troisJoursPlusTard = $abonnement->getCreatedAt()->modify('+73 hours');

        try {
            $this->funnel()->confirmEmailAndStartTrial($jeton, $troisJoursPlusTard);
            self::fail('un lien expire aurait du etre refuse');
        } catch (ExpiredConfirmationLinkException) {
            // attendu
        }

        self::assertSame(SubscriptionStatus::Draft, $abonnement->getStatus());
        self::assertSame($etablissementsAvant, $this->countEstablishments());
    }

    /**
     * Le vrai bus pose bien l'empreinte du jeton — donc l'abonne est branche.
     *
     * ⚠ **Ce test ne fait pas double emploi avec les autres.** Les autres appellent l'abonne a la
     * main pour capturer le jeton en clair, ce qu'aucun appelant reel ne peut faire : ils prouvent
     * la logique et **pas le cablage**. Si `#[AsEventListener]` disparaissait, ou si le nom de
     * l'evenement changeait d'un seul cote, ils resteraient tous verts et aucun prospect ne
     * recevrait jamais de courriel. C'est precisement le defaut qu'un bus rend silencieux.
     */
    public function testLeBusReelDeclencheLenvoiDeLaConfirmation(): void
    {
        $this->offre();

        $abonnement = $this->funnel()->openCart('Par le bus', self::EMAIL, self::PLAN_CODE, [self::COMPRISE], $this->at());
        self::assertNull($abonnement->getEmailConfirmationTokenHash(), 'aucune empreinte avant la demande');

        $this->funnel()->requestTrial($abonnement, $this->at());

        self::assertNotNull(
            $abonnement->getEmailConfirmationTokenHash(),
            'le bus n a pas atteint l abonne : aucun courriel de confirmation ne partirait'
        );
    }

    /**
     * Le mandat se signe PENDANT l'essai — c'est ce qui rend la bascule payante possible.
     *
     * ⚠ Avant le 04/09, `signMandate()` refusait tout abonnement qui n'etait pas un brouillon. Un
     * essai est actif : la signature etait donc impossible, et l'echeance ne pouvait que suspendre.
     * On ne s'en serait apercu qu'au quatorzieme jour du premier client.
     */
    public function testLeMandatSeSigneEncorePendantLessai(): void
    {
        $this->offre();
        $this->roleTemplate();

        $abonnement = $this->funnel()->openCart('Signe pendant', self::EMAIL, self::PLAN_CODE, [self::COMPRISE], $this->at());
        $jeton = $this->demanderEtCapturerLeJeton($abonnement);
        $this->funnel()->confirmEmailAndStartTrial($jeton, $this->at());

        self::assertSame(SubscriptionStatus::Active, $abonnement->getStatus());

        $mandat = $this->funnel()->signMandate($abonnement, self::IBAN, 'AGRIFRPPXXX', 'Signe pendant', $this->at()->modify('+3 days'));

        self::assertSame($this->mandates()->reference($abonnement), $mandat->getRum());
        self::assertNotNull($this->mandates()->active($abonnement));
    }

    // ---------------------------------------------------------------- montage

    private ?\DateTimeImmutable $instant = null;

    /**
     * L'instant de reference des tests — l'heure REELLE, et pas une date fixe.
     *
     * ⚠ Les tests du tunnel payant se placent au 15/09/2026, ce qui ne genait personne tant qu'aucune
     * regle ne comparait deux instants. Le lien de confirmation, lui, expire 72 h apres la creation
     * du panier — et `createdAt` est pose par le constructeur de l'entite, donc a l'heure reelle. Une
     * date fixe dans le futur fabriquait un panier vieux de plusieurs jours des sa naissance, et les
     * trois tests du chemin nominal echouaient sur une expiration qu'aucun vrai prospect ne peut
     * rencontrer. Memorise, pour que deux appels rendent la meme valeur.
     */
    // ---------------------------------------------------------- l'outil de recette (E-8 non levé)

    /**
     * **FERMÉ PAR DÉFAUT, ET SANS RIEN ÉCRIRE.**
     *
     * Le drapeau absent, la commande doit refuser. Mais refuser d'afficher ne suffit pas : si elle
     * frappait le jeton avant de refuser, elle invaliderait le lien déjà parti chez le prospect —
     * une commande « sans effet » qui casse le parcours qu'elle prétend seulement observer.
     *
     * On vérifie donc les deux : le refus, ET que l'empreinte en base n'a pas bougé.
     */
    public function testLoutilDeRecetteRefuseEtNecritRienSansLeDrapeau(): void
    {
        $abonnement = $this->abonnementAuPanier();
        $this->demanderEtCapturerLeJeton($abonnement);
        $empreinteAvant = $abonnement->getEmailConfirmationTokenHash();
        self::assertNotNull($empreinteAvant, 'témoin : une demande doit porter une empreinte avant ce test');

        $testeur = new CommandTester(new IssueTrialConfirmationLinkCommand(
            $this->em(),
            new TrialConfirmationLink($this->em(), 'https://vitrine.exemple.test'),
            false,
        ));
        $code = $testeur->execute(['demande' => $abonnement->getId()->toRfc4122()]);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('TRIAL_CONFIRMATION_BYPASS', $testeur->getDisplay());

        $this->em()->refresh($abonnement);
        self::assertSame(
            $empreinteAvant,
            $abonnement->getEmailConfirmationTokenHash(),
            'la commande fermée ne doit pas frapper de jeton : elle invaliderait le lien du prospect',
        );
    }

    /**
     * **LE TÉMOIN QUI JUSTIFIE L'OUTIL : son lien ouvre vraiment l'essai.**
     *
     * On ne vérifie pas qu'il imprime quelque chose — on prend le lien qu'il rend et on le fait
     * confirmer. S'il ouvre l'essai, l'outil emprunte le même chemin que le courriel du prospect.
     * S'il ne l'ouvrait pas, il prouverait son propre chemin, donc rien : c'est exactement le risque
     * qu'écarte le service partagé entre la commande et l'écouteur.
     */
    public function testLeLienDeLoutilOuvreReellementLessai(): void
    {
        $abonnement = $this->abonnementAuPanier();

        $testeur = new CommandTester(new IssueTrialConfirmationLinkCommand(
            $this->em(),
            new TrialConfirmationLink($this->em(), 'https://vitrine.exemple.test'),
            true,
        ));
        self::assertSame(Command::SUCCESS, $testeur->execute(['demande' => $abonnement->getId()->toRfc4122()]));

        $affichage = $testeur->getDisplay();
        self::assertStringContainsString('https://vitrine.exemple.test/confirmation.html?jeton=', $affichage);

        $jeton = $this->jetonDuLien($affichage);
        self::assertNotSame($jeton, $abonnement->getEmailConfirmationTokenHash(), 'la base ne doit jamais porter le jeton en clair');

        $confirme = $this->funnel()->confirmEmailAndStartTrial($jeton, $this->at());

        self::assertSame($abonnement->getId(), $confirme->getId());
        self::assertNotNull($confirme->getEmailConfirmedAt(), 'le lien de l\'outil doit ouvrir l\'essai');
    }

    /**
     * **IL DIT QUE LE LIEN EST DÉJÀ MORT, PLUTÔT QUE DE LAISSER CHERCHER.**
     *
     * La fenêtre se compte depuis la création de la demande — frapper un jeton neuf ne la rouvre
     * pas. Sur une demande ancienne, l'outil rend donc un lien d'apparence normale qui répondra
     * « expiré ». Sans l'avertissement, on chercherait le défaut dans le tunnel plutôt que dans
     * l'âge de la demande.
     */
    public function testLoutilAvertitQuandLaFenetreEstDejaFermee(): void
    {
        $abonnement = $this->abonnementAuPanier();
        $this->vieillirLaDemande($abonnement, SubscriptionFunnel::CONFIRMATION_HOURS + 1);

        $testeur = new CommandTester(new IssueTrialConfirmationLinkCommand(
            $this->em(),
            new TrialConfirmationLink($this->em(), 'https://vitrine.exemple.test'),
            true,
        ));
        $testeur->execute(['demande' => $abonnement->getId()->toRfc4122()]);

        self::assertStringContainsString('DÉJÀ EXPIRÉ', $testeur->getDisplay());

        // ⚠ ET LE LIEN EST BIEN MORT — l'avertissement doit décrire la réalité, pas la remplacer.
        $this->expectException(ExpiredConfirmationLinkException::class);
        $this->funnel()->confirmEmailAndStartTrial($this->jetonDuLien($testeur->getDisplay()), $this->at());
    }

    /** Le jeton en clair, tel qu'un prospect le lirait dans son message. */
    private function jetonDuLien(string $affichage): string
    {
        self::assertSame(1, preg_match('/jeton=([0-9a-f]+)/', $affichage, $trouve), 'aucun lien dans la sortie');

        return $trouve[1];
    }

    /** Recule la création de la demande, seule chose qui borne la validité du lien. */
    private function vieillirLaDemande(Subscription $abonnement, int $heures): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE subscription_subscription SET created_at = :quand WHERE id = :id',
            [
                'quand' => $this->at()->modify(sprintf('-%d hours', $heures))->format('Y-m-d H:i:s'),
                'id' => $abonnement->getId()->toBinary(),
            ],
        );
        $this->em()->refresh($abonnement);
    }

    /** Une demande d'essai au panier, montee comme les autres tests de ce fichier. */
    private function abonnementAuPanier(): Subscription
    {
        $this->offre();

        return $this->funnel()->openCart(
            'Recette du tunnel',
            self::EMAIL,
            self::PLAN_CODE,
            [self::COMPRISE],
            $this->at(),
        );
    }

    private function at(): \DateTimeImmutable
    {
        return $this->instant ??= new \DateTimeImmutable();
    }

    /**
     * Joue l'abonne de `subscription.trial_requested` a la main et rend le jeton **en clair**.
     *
     * Aucun autre chemin ne le permet, et c'est voulu : le jeton n'existe en clair que le temps de
     * composer le message. Le capturer ici, c'est se mettre exactement a la place du prospect qui
     * ouvre sa boite — on lit le lien qu'il aurait recu, pas une valeur qu'on aurait choisie.
     */
    private function demanderEtCapturerLeJeton(Subscription $abonnement): string
    {
        $notifieur = new class implements ClientNotifierInterface {
            public ?ClientNotification $dernier = null;

            public function notify(ClientNotification $notification): NotificationOutcome
            {
                $this->dernier = $notification;

                return NotificationOutcome::Journalisee;
            }
        };

        $abonne = new SendTrialConfirmationEmail(
            $this->em(),
            $notifieur,
            new TrialConfirmationLink($this->em(), 'https://vitrine.exemple.test'),
        );
        $abonne(new DomainEvent(
            'subscription.trial_requested',
            new EventTenant($this->editeur()->getId()),
            new EventSubject('Subscription', $abonnement->getId()->toRfc4122()),
            ['planCode' => self::PLAN_CODE, 'trialDays' => SubscriptionFunnel::TRIAL_DAYS],
            null,
            $this->at(),
        ));

        self::assertNotNull($notifieur->dernier, 'aucun courriel de confirmation compose');
        $lien = (string) ($notifieur->dernier->variables['lienConfirmation'] ?? '');
        self::assertStringStartsWith(
            'https://vitrine.exemple.test/confirmation.html?jeton=',
            $lien,
            'le lien doit pointer sur la vitrine, pas sur le back-office'
        );

        $jeton = substr($lien, (int) strpos($lien, 'jeton=') + 6);
        self::assertNotSame('', $jeton);
        self::assertNotSame($jeton, $abonnement->getEmailConfirmationTokenHash(), 'la base ne doit jamais porter le jeton en clair');

        return $jeton;
    }

    private function funnel(): SubscriptionFunnel
    {
        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);
        /** @var TokenisationIbanInterface $tokenisation */
        $tokenisation = static::getContainer()->get(TokenisationIbanInterface::class);
        /** @var ChiffreurIbanInterface $chiffreur */
        $chiffreur = static::getContainer()->get(ChiffreurIbanInterface::class);

        return new SubscriptionFunnel(
            $this->em(),
            new OfferCatalog($this->em(), new CatalogueCapacites()),
            $this->editorTenant(),
            new SubscriptionActivator($this->em(), $bus, $this->editorTenant()),
            $tokenisation,
            $chiffreur,
            new DemoConfiguration([]),
            $bus,
            $this->mandates(),
        );
    }

    private function mandates(): SubscriptionMandates
    {
        return new SubscriptionMandates($this->em());
    }

    private function editorTenant(): EditorTenantResolver
    {
        return new EditorTenantResolver($this->em(), $this->editeur()->getId()->toRfc4122());
    }

    private function editeur(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($etablissement instanceof Etablissement);

        return $etablissement;
    }

    private function moduleAccess(): ModuleAccess
    {
        /** @var Fonctionnalites $features */
        $features = static::getContainer()->get(Fonctionnalites::class);

        return new ModuleAccess(new ModuleRegistry([]), $features);
    }

    private function offre(): void
    {
        $plan = (new Plan())
            ->setCode(self::PLAN_CODE)
            ->setLabel('Essentiel')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities([self::COMPRISE])
            ->setActive(true);
        $this->em()->persist($plan);

        $option = (new PlanOption())
            ->setCapability(self::OPTION)
            ->setLabel('Reservation')
            ->setMonthlyPriceCents(1500);
        $this->em()->persist($option);

        $this->em()->flush();
    }

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

    private function provisioningOf(Subscription $abonnement): ?ProvisioningRequest
    {
        return $this->em()->getRepository(ProvisioningRequest::class)->findOneBy(['subscription' => $abonnement]);
    }

    private function countEstablishments(): int
    {
        return \count($this->em()->getRepository(Etablissement::class)->findAll());
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
