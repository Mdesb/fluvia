<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\Platform\Event\EventBus;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\Platform\Notification\NotificationOutcome;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Subscription\Service\ProvisioningService;
use App\Subscription\Service\SubscriptionActivator;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-9 — le client qui vient de payer reçoit ses accès.
 *
 * **Sans ce courriel, le provisionnement est muet.** Un client paie, son établissement est créé, son
 * compte administrateur existe — et personne ne le lui dit. Il attend, puis il appelle. C'est le
 * dernier maillon entre « il a payé » et « il utilise ».
 *
 * Ces tests passent par le **vrai bus** : activation → `subscription.activated` → provisionnement →
 * `establishment.provisioned` → courriel. Deux événements, trois abonnés, et rien qui s'appelle
 * directement — c'est le découplage de D2 vérifié de bout en bout.
 */
final class CourrielDeBienvenueTest extends SocleApiTestCase
{
    private const EMAIL = 'contact@campingdespins.test';

    /** Le chemin complet : l'abonnement s'active, et le client reçoit ses accès. */
    public function testLeClientRecoitSesAccesApresLeProvisionnement(): void
    {
        $espion = $this->espionner();
        $this->roleTemplate();
        $abonnement = $this->abonnement();

        $this->activateur()->activate($abonnement, $this->at());

        self::assertCount(1, $espion->recues, 'un courriel de bienvenue doit partir');

        $notification = $espion->recues[0];
        self::assertSame(NotificationChannel::Email, $notification->channel);
        self::assertSame('abonnement.bienvenue', $notification->templateKey);
        self::assertSame('Camping des Pins', $notification->variables['etablissement']);
    }

    /**
     * **La base légale est contractuelle, et déclarée explicitement.**
     *
     * Le défaut de l'énumération est `Consentement` : qui ne se pose pas la question voit son message
     * refusé. Livrer ses identifiants à quelqu'un qui vient d'acheter est dû au titre du contrat —
     * mais il faut le dire, et ce test vérifie qu'on l'a dit.
     */
    public function testLaBaseLegaleEstContractuelleEtNonLeDefaut(): void
    {
        $espion = $this->espionner();
        $this->roleTemplate();

        $this->activateur()->activate($this->abonnement(), $this->at());

        self::assertSame(NotificationBasis::Contractuelle, $espion->recues[0]->basis);
        self::assertNotSame(NotificationBasis::Consentement, $espion->recues[0]->basis);
    }

    /**
     * **Le jeton envoyé est celui qui ouvre le compte, et c'est le seul en circulation.**
     *
     * L'événement ne transporte aucun secret : celui qui délivre le jeton le frappe au moment de
     * l'envoi. On vérifie ici que le jeton du courriel correspond bien à l'empreinte stockée — sinon
     * le client recevrait un lien qui ne marche pas, ce qui est pire que pas de courriel.
     */
    public function testLeJetonEnvoyeEstCeluiQuiOuvreLeCompte(): void
    {
        $espion = $this->espionner();
        $this->roleTemplate();

        $this->activateur()->activate($this->abonnement(), $this->at());

        $jeton = $espion->recues[0]->variables['jetonActivation'];
        self::assertIsString($jeton);
        self::assertNotSame('', $jeton);

        $administrateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $administrateur);
        self::assertSame(hash('sha256', $jeton), $administrateur->getJetonInvitation());
    }

    /**
     * Un rejeu n'envoie pas un second courriel.
     *
     * Le provisionnement est idempotent, donc `establishment.provisioned` n'est annoncé qu'une fois.
     * Sans cela, un rappel bancaire répété enverrait au client autant de courriels de bienvenue —
     * chacun invalidant le lien du précédent.
     */
    public function testUnRejeuNenvoiePasDeSecondCourriel(): void
    {
        $espion = $this->espionner();
        $this->roleTemplate();
        $abonnement = $this->abonnement();

        $this->activateur()->activate($abonnement, $this->at());
        // Le provisionnement est rejoué directement : l'abonnement est déjà actif, on ne peut pas
        // repasser par l'activation.
        $this->provisionnement()->provision($abonnement, $this->at());

        self::assertCount(1, $espion->recues, 'un seul courriel, quel que soit le nombre de rejeux');
    }

    // ---------------------------------------------------------------- montage

    private function espionner(): object
    {
        $espion = new class implements ClientNotifierInterface {
            /** @var list<ClientNotification> */
            public array $recues = [];

            public function notify(ClientNotification $notification): NotificationOutcome
            {
                $this->recues[] = $notification;

                return NotificationOutcome::Journalisee;
            }
        };

        static::getContainer()->set(ClientNotifierInterface::class, $espion);

        return $espion;
    }

    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-15 10:00:00');
    }

    private function activateur(): SubscriptionActivator
    {
        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);

        return new SubscriptionActivator($this->em(), $bus, $this->editorTenant());
    }

    private function provisionnement(): ProvisioningService
    {
        /** @var ProvisioningService $service */
        $service = static::getContainer()->get(ProvisioningService::class);

        return $service;
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

    private function roleTemplate(): Role
    {
        $permission = (new Permission())->setModule('organisation')->setAction('administrer');
        $this->em()->persist($permission);

        $role = (new Role())->setNom(ProvisioningService::ADMIN_ROLE_TEMPLATE)->setEstModele(true);
        $role->addPermission($permission);
        $this->em()->persist($role);
        $this->em()->flush();

        return $role;
    }

    private function abonnement(): Subscription
    {
        $editeur = $this->editeur();

        $prospect = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale('Camping des Pins')
            ->setEmail(self::EMAIL)
            ->setGroupe($editeur->getRegion()?->getGroupe())
            ->setEtablissementCreation($editeur);
        $this->em()->persist($prospect);

        $plan = (new Plan())
            ->setCode('essentiel')
            ->setLabel('Essentiel')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities(['controle_acces'])
            ->setActive(true);
        $this->em()->persist($plan);

        $abonnement = (new Subscription())
            ->setCustomerReference($prospect->getId()->toRfc4122())
            ->setPlan($plan);

        $this->em()->persist($abonnement);
        $this->em()->flush();

        return $abonnement;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
