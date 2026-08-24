<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\Platform\Event\EventBus;
use App\Platform\Module\ModuleAccess;
use App\Platform\Module\ModuleRegistry;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Subscription\Entity\ProvisioningRequest;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\ProvisioningStatus;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\InvalidOfferException;
use App\Subscription\Exception\MissingMandateException;
use App\Subscription\Service\OfferCatalog;
use App\Subscription\Service\ProvisioningService;
use App\Subscription\Service\SubscriptionActivator;
use App\Subscription\Service\SubscriptionFunnel;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-3 — le tunnel de bout en bout : panier, mandat, confirmation, plateforme livrée (CA-1).
 *
 * Ce test suit le chemin d'un vrai client : il compose, il signe, il paie, et il doit trouver sa
 * plateforme ouverte. Les cas d'abandon comptent autant — un tunnel de vente est fait pour être
 * quitté en cours de route, et ce qui reste derrière ne doit jamais avoir ouvert de service.
 */
final class SubscriptionFunnelTest extends SocleApiTestCase
{
    private const COMPRISE = 'controle_acces';
    private const OPTION = 'reservation';
    private const NON_SOUSCRITE = 'no_show';
    private const NON_VENDABLE = 'casiers';

    private const PLAN_CODE = 'formule_essentiel';
    private const EMAIL = 'camping@exemple.test';
    private const IBAN = 'FR7630006000011234567890189';

    /** Le chemin nominal : trois étapes, et la plateforme du client existe. */
    public function testLeTunnelComplerLivreLaPlateformeDuClient(): void
    {
        $this->offre();
        $this->roleTemplate();
        $funnel = $this->funnel();

        $subscription = $funnel->openCart('Camping des Pins', self::EMAIL, self::PLAN_CODE, [self::COMPRISE, self::OPTION], $this->at());
        self::assertSame(SubscriptionStatus::Draft, $subscription->getStatus(), 'le panier ne vend rien tant qu il n est pas confirmé');
        self::assertNull($this->provisioningOf($subscription), 'aucun provisionnement avant confirmation');

        $funnel->signMandate($subscription, self::IBAN, 'AGRIFRPPXXX', 'Camping des Pins', $this->at());
        self::assertNull($this->provisioningOf($subscription), 'signer un mandat n ouvre encore aucun service');

        $funnel->confirmPayment($subscription, $this->at());

        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());

        $request = $this->provisioningOf($subscription);
        self::assertInstanceOf(ProvisioningRequest::class, $request);
        self::assertSame(ProvisioningStatus::Completed, $request->getStatus());

        $etablissement = $request->getEstablishment();
        self::assertInstanceOf(Etablissement::class, $etablissement);
        self::assertSame('Camping des Pins', $etablissement->getNom());

        $access = $this->moduleAccess();
        self::assertTrue($access->hasModule($etablissement, self::COMPRISE));
        self::assertTrue($access->hasModule($etablissement, self::OPTION));
        self::assertFalse($access->hasModule($etablissement, self::NON_SOUSCRITE));
    }

    /**
     * Le prospect abandonne après le mandat : rien n'est ouvert, le mandat reste orphelin (spec §7).
     *
     * C'est le cas explicitement prévu par la spec. Ce qu'on vérifie ici n'est pas qu'il ne se passe
     * rien — c'est qu'aucun établissement n'a été créé, donc qu'aucun service n'est ouvert sans
     * contrepartie.
     */
    public function testAbandonApresLeMandatNouvreAucunService(): void
    {
        $this->offre();
        $this->roleTemplate();
        $etablissementsAvant = $this->countEstablishments();

        $subscription = $this->funnel()->openCart('Piscine du Lac', self::EMAIL, self::PLAN_CODE, [self::COMPRISE], $this->at());
        $this->funnel()->signMandate($subscription, self::IBAN, 'AGRIFRPPXXX', 'Piscine du Lac', $this->at());

        self::assertSame($etablissementsAvant, $this->countEstablishments());
        self::assertNull($this->provisioningOf($subscription));
        self::assertInstanceOf(MandatSepa::class, $this->em()->getRepository(MandatSepa::class)->findOneBy(['statut' => StatutMandatSepa::Actif]));
    }

    /** Confirmer sans mandat est refusé, et rien n'est créé. */
    public function testConfirmerSansMandatEstRefuse(): void
    {
        $this->offre();
        $this->roleTemplate();
        $etablissementsAvant = $this->countEstablishments();

        $subscription = $this->funnel()->openCart('Sans mandat', self::EMAIL, self::PLAN_CODE, [self::COMPRISE], $this->at());

        try {
            $this->funnel()->confirmPayment($subscription, $this->at());
            self::fail('la confirmation aurait dû être refusée');
        } catch (MissingMandateException) {
            // attendu
        }

        self::assertSame(SubscriptionStatus::Draft, $subscription->getStatus());
        self::assertSame($etablissementsAvant, $this->countEstablishments());
    }

    /**
     * Le prospect s'est trompé d'IBAN et resigne : un seul mandat, corrigé.
     *
     * Avec une référence de mandat tirée au hasard, chaque correction créerait un mandat de plus pour
     * le même client, et la remise suivante ne saurait pas lequel présenter.
     */
    public function testResignerCorrigeLeMandatAuLieuDenCreerUnSecond(): void
    {
        $this->offre();
        $funnel = $this->funnel();

        $subscription = $funnel->openCart('Hôtel du Port', self::EMAIL, self::PLAN_CODE, [self::COMPRISE], $this->at());

        $premier = $funnel->signMandate($subscription, self::IBAN, 'AGRIFRPPXXX', 'Hotel du Port', $this->at());
        $second = $funnel->signMandate($subscription, 'FR1420041010050500013M02606', 'CCBPFRPPXXX', 'Hotel du Port', $this->at());

        self::assertSame($premier->getRum(), $second->getRum());
        self::assertCount(1, $this->em()->getRepository(MandatSepa::class)->findBy(['rum' => $premier->getRum()]));
        self::assertSame('2606', $second->getIban4Derniers(), 'le mandat doit porter le nouvel IBAN');
    }

    /** Une capacité réelle mais non mise en vente est refusée avant tout enregistrement (RG-ED-03). */
    public function testUneCapaciteNonVendableEstRefuseeAuPanier(): void
    {
        $this->offre();
        $clientsAvant = \count($this->em()->getRepository(Client::class)->findAll());

        $this->expectException(InvalidOfferException::class);

        try {
            $this->funnel()->openCart('Refusé', self::EMAIL, self::PLAN_CODE, [self::NON_VENDABLE], $this->at());
        } finally {
            self::assertSame($clientsAvant, \count($this->em()->getRepository(Client::class)->findAll()), 'aucun prospect ne doit rester derrière un panier refusé');
        }
    }

    // ---------------------------------------------------------------- montage

    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-15 10:00:00');
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
        );
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

    /** Une formule et une option en vente, avec des capacités réelles du catalogue technique. */
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
            ->setLabel('Réservation')
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

    private function provisioningOf(Subscription $subscription): ?ProvisioningRequest
    {
        return $this->em()->getRepository(ProvisioningRequest::class)->findOneBy(['subscription' => $subscription]);
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
