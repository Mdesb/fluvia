<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Subscription\Exception\InvalidOfferException;
use App\Subscription\Service\OfferCatalog;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-1 — le catalogue commercial ne peut vendre que des capacités réelles (RG-ED-03).
 *
 * Test d'intégration : tout l'intérêt du service est qu'il confronte l'offre au **vrai** catalogue de
 * capacités. Le vérifier contre un double ne prouverait que l'arithmétique.
 *
 * Les codes utilisés sont ceux du catalogue réel : `controle_acces`, `reservation`, `no_show`.
 */
final class OfferCatalogTest extends SocleApiTestCase
{
    private const COMPRISE = 'controle_acces';
    private const OPTION = 'reservation';
    private const OPTION_2 = 'no_show';
    private const NON_VENDABLE = 'casiers';

    public function testUneCapaciteCompriseNestPasFactureeEnSupplement(): void
    {
        $plan = $this->plan(4900, [self::COMPRISE]);
        $this->option(self::OPTION, 1500);

        $catalogue = $this->catalogue();

        self::assertSame([], $catalogue->billableExtras($plan, [self::COMPRISE]));
        self::assertSame(4900, $catalogue->monthlyPriceCents($plan, [self::COMPRISE]));
    }

    public function testUneOptionHorsFormuleEstFacturee(): void
    {
        $plan = $this->plan(4900, [self::COMPRISE]);
        $this->option(self::OPTION, 1500);

        $catalogue = $this->catalogue();

        self::assertSame([self::OPTION], $catalogue->billableExtras($plan, [self::OPTION]));
        self::assertSame(6400, $catalogue->monthlyPriceCents($plan, [self::OPTION]));
    }

    public function testLePrixAdditionneLaFormuleEtChaqueOption(): void
    {
        $plan = $this->plan(4900, [self::COMPRISE]);
        $this->option(self::OPTION, 1500);
        $this->option(self::OPTION_2, 900);

        $prix = $this->catalogue()->monthlyPriceCents($plan, [self::COMPRISE, self::OPTION, self::OPTION_2]);

        self::assertSame(4900 + 1500 + 900, $prix);
    }

    /** Cocher deux fois la même option ne la facture pas deux fois. */
    public function testLesDoublonsSontIgnores(): void
    {
        $plan = $this->plan(4900, []);
        $this->option(self::OPTION, 1500);

        $catalogue = $this->catalogue();

        self::assertSame([self::OPTION], $catalogue->billableExtras($plan, [self::OPTION, self::OPTION, self::OPTION]));
        self::assertSame(6400, $catalogue->monthlyPriceCents($plan, [self::OPTION, self::OPTION]));
    }

    /** RG-ED-03 — on ne vend pas un code que le catalogue technique ne connaît pas. */
    public function testCapaciteInconnueDuCatalogueRefusee(): void
    {
        $plan = $this->plan(4900, []);

        $this->expectException(InvalidOfferException::class);
        $this->expectExceptionMessageMatches('/inconnue du catalogue/');

        $this->catalogue()->billableExtras($plan, ['module_qui_nexiste_pas']);
    }

    /**
     * Capacité réelle, mais que personne n'a mise en vente : échec fermé.
     *
     * L'ignorer en silence produirait un client convaincu d'avoir acheté un module qu'il n'aura jamais
     * — et il ne s'en apercevrait qu'après avoir payé.
     */
    public function testCapaciteReelleMaisNonVendableRefusee(): void
    {
        $plan = $this->plan(4900, []);

        $this->expectException(InvalidOfferException::class);
        $this->expectExceptionMessageMatches('/n\'est pas vendable/');

        $this->catalogue()->billableExtras($plan, [self::NON_VENDABLE]);
    }

    /** Une formule qui promet une capacité fantôme se vend normalement et ne se livre jamais. */
    public function testUneFormuleQuiIncluraitUneCapaciteFantomeEstRefusee(): void
    {
        $plan = $this->plan(4900, ['capacite_fantome']);

        $this->expectException(InvalidOfferException::class);

        $this->catalogue()->assertPlanIsCoherent($plan);
    }

    public function testUneOptionDesactiveeNestPlusVendable(): void
    {
        $plan = $this->plan(4900, []);
        $option = $this->option(self::OPTION, 1500);
        $option->setActive(false);
        $this->em()->flush();

        $this->expectException(InvalidOfferException::class);

        $this->catalogue()->billableExtras($plan, [self::OPTION]);
    }

    /**
     * Construit le service directement plutôt que de le demander au conteneur.
     *
     * `OfferCatalog` est privé et personne ne le consomme encore : Symfony l'élimine donc à la
     * compilation. Le rendre public pour les besoins d'un test reviendrait à modifier la production
     * pour arranger le test — on instancie, c'est tout, et `CatalogueCapacites` n'a pas de dépendance.
     */
    private function catalogue(): OfferCatalog
    {
        return new OfferCatalog($this->em(), new CatalogueCapacites());
    }

    /** @param list<string> $comprises */
    private function plan(int $prixCents, array $comprises): Plan
    {
        $plan = (new Plan())
            ->setCode('plan_'.bin2hex(random_bytes(4)))
            ->setLabel('Formule de test')
            ->setMonthlyPriceCents($prixCents)
            ->setIncludedCapabilities($comprises);

        $this->em()->persist($plan);
        $this->em()->flush();

        return $plan;
    }

    private function option(string $capacite, int $prixCents): PlanOption
    {
        $option = (new PlanOption())
            ->setCapability($capacite)
            ->setLabel('Option '.$capacite)
            ->setMonthlyPriceCents($prixCents);

        $this->em()->persist($option);
        $this->em()->flush();

        return $option;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
