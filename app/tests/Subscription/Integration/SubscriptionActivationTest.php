<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\Platform\Event\EventBus;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\ProvisioningRequest;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\ProvisioningStatus;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\UnknownCustomerException;
use App\Subscription\Service\ProvisioningService;
use App\Subscription\Service\SubscriptionActivator;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ED-3, RG-ED-04 — l'activation annonce un fait, et c'est le fait qui provisionne.
 *
 * Ce test passe par le **vrai bus** et le **vrai abonné**, pas par un appel direct au provisioning.
 * C'est tout l'enjeu de D2 : si demain quelqu'un remplace la publication par un appel de méthode, le
 * comportement observable resterait identique et seul un test comme celui-ci verrait la différence —
 * au moment où l'on voudrait rejouer l'événement, ou y accrocher un courriel de bienvenue.
 */
final class SubscriptionActivationTest extends SocleApiTestCase
{
    private const COMPRISE = 'controle_acces';
    private const OPTION = 'reservation';
    private const EMAIL_PROSPECT = 'tunnel@exemple.test';

    /** Activer un abonnement payé suffit à faire naître l'établissement du client. */
    public function testLactivationProvisionneLetablissementParLeBus(): void
    {
        $this->roleTemplate();
        $subscription = $this->subscription($this->prospect());

        $this->activator()->activate($subscription, $this->at());

        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());

        $request = $this->em()->getRepository(ProvisioningRequest::class)->findOneBy(['subscription' => $subscription]);
        self::assertInstanceOf(ProvisioningRequest::class, $request, 'l\'abonné du bus doit avoir provisionné');
        self::assertSame(ProvisioningStatus::Completed, $request->getStatus());
        self::assertInstanceOf(Etablissement::class, $request->getEstablishment());

        self::assertInstanceOf(
            Utilisateur::class,
            $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL_PROSPECT]),
        );
    }

    /**
     * Un abonnement sans fiche client échoue à l'activation, il ne s'active pas « à moitié ».
     *
     * Sans client, on ignore dans quel périmètre l'abonnement est vendu — et un événement sans tenant
     * est refusé par le contrat (D3). Mieux vaut échouer ici que publier un fait rattaché au mauvais
     * établissement.
     */
    public function testUnAbonnementSansFicheClientNeSactivePas(): void
    {
        $subscription = $this->subscription(null);

        $this->expectException(UnknownCustomerException::class);

        $this->activator()->activate($subscription, $this->at());
    }

    // ---------------------------------------------------------------- montage

    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-15 10:00:00');
    }

    private function activator(): SubscriptionActivator
    {
        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);

        return new SubscriptionActivator($this->em(), $bus, $this->editorTenant());
    }

    /**
     * La désignation de l'éditeur, construite ici plutôt que lue dans l'environnement.
     *
     * `EDITOR_TENANT_ID` est vide par défaut et se renseigne dans `.env.local`, qui n'est pas
     * versionné : un test qui en dépendrait passerait chez moi et échouerait chez tout le monde.
     * Le service accepte sa valeur par constructeur — on la lui donne, et le refus délibéré de tout
     * repli (D36) reste intact.
     */
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

    private function prospect(): Client
    {
        $editeur = $this->editeur();

        $prospect = (new Client())
            ->setType(TypeClient::Morale)
            ->setGroupe($editeur->getRegion()?->getGroupe())
            ->setEtablissementCreation($editeur)
            ->setNom('Bernard')
            ->setPrenom('Luc')
            ->setRaisonSociale('Piscine du Lac')
            ->setEmail(self::EMAIL_PROSPECT);

        $this->em()->persist($prospect);
        $this->em()->flush();

        return $prospect;
    }

    private function subscription(?Client $prospect): Subscription
    {
        $plan = (new Plan())
            ->setCode('plan_'.bin2hex(random_bytes(4)))
            ->setLabel('Formule de test')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities([self::COMPRISE]);
        $this->em()->persist($plan);

        $subscription = (new Subscription())
            // Référence volontairement inexistante quand il n'y a pas de prospect : c'est le cas que
            // le second test observe.
            ->setCustomerReference($prospect?->getId()->toRfc4122() ?? Uuid::v4()->toRfc4122())
            ->setPlan($plan);
        $subscription->addOption(self::OPTION, 1500, $this->at());

        $this->em()->persist($subscription);
        $this->em()->flush();

        return $subscription;
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

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
