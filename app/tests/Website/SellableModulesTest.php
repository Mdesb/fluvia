<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Subscription\Entity\PlanOption;
use App\Tests\SocleApiTestCase;
use App\Website\Port\SellableModulesSource;
use App\Website\Service\ModuleCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le site public ne montre que ce que l'éditeur vend, et le lien est automatique.
 *
 * **Le défaut corrigé (arbitrage de Maxime, 05/09).** La page listait ses modules depuis le
 * catalogue des CAPACITÉS. Trois d'entre elles n'ont aucune option active : la page annonçait donc
 * « 20 modules » et en vantait trois qu'un visiteur ne pouvait pas acheter, pendant que la section
 * Tarifs — qui lit le vrai catalogue — n'en proposait que dix-sept. **La même page se
 * contredisait**, et rien ne le signalait.
 *
 * ⚠ **CE TEST FABRIQUE SA PROPRE DONNÉE.** Le harnais ne sème aucune option de vente : les vingt
 * options n'existent que dans le catalogue réel de Maxime. Un test qui aurait supposé leur présence
 * serait passé au vert sans rien mesurer le jour où elles changent — c'est exactement le genre de
 * témoin creux qu'on ne veut pas ici.
 */
final class SellableModulesTest extends SocleApiTestCase
{
    /**
     * ⚠ **LE TEST QUI COMPTE : RETIRER UNE OPTION DE LA VENTE LA RETIRE DE LA PAGE.**
     *
     * C'est l'automatisme qu'on éprouve, pas la liste du jour. Vérifier que tel module est présent
     * serait vrai le jour où on l'écrit et faux au premier changement de catalogue. Ici on agit sur
     * le catalogue et on regarde la page bouger : si le lien se rompt — une liste recopiée, un
     * cache, un repli sur « tout montrer » — ce témoin tombe.
     */
    public function testRetirerUneOptionDeLaVenteLaRetireDeLaPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $catalogue = static::getContainer()->get(ModuleCatalog::class);

        $module = $catalogue->parSlug('casiers') ?? $catalogue->modules()[0];
        $libelle = $module['libelle'];

        $option = (new PlanOption())
            ->setCapability($module['code'])
            ->setLabel($libelle)
            ->setMonthlyPriceCents(1900)
            ->setActive(true);

        $em->persist($option);
        $em->flush();

        $avant = (string) $client->request('GET', '/')->getContent();
        self::assertStringContainsString(
            $libelle,
            $avant,
            'un module en vente doit figurer sur la page',
        );

        $option->setActive(false);
        $em->flush();

        $apres = (string) $client->request('GET', '/')->getContent();
        self::assertStringNotContainsString(
            $libelle,
            $apres,
            'un module retiré de la vente reste annoncé : la page vante ce qu’on ne peut pas acheter',
        );
    }

    /**
     * **Le port dit la même chose que le catalogue, sans filtre supplémentaire.**
     *
     * La tentation serait d'ajouter une règle d'affichage dans l'adaptateur — écarter les options
     * sans prix, sans description… Chacune ferait de lui une seconde décision de vente, prise
     * ailleurs qu'à l'endroit qui décide, et le site recommencerait à diverger du tunnel.
     */
    public function testLePortNajouteAucunFiltre(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $source = static::getContainer()->get(SellableModulesSource::class);

        $em->persist((new PlanOption())
            ->setCapability('casiers')
            ->setLabel('Casiers')
            ->setMonthlyPriceCents(1900)
            ->setActive(true));
        $em->persist((new PlanOption())
            ->setCapability('stock')
            ->setLabel('Suivi de stock')
            ->setMonthlyPriceCents(0)
            ->setActive(true));
        $em->flush();

        $attendues = array_map(
            static fn (PlanOption $o): string => $o->getCapability(),
            $em->getRepository(PlanOption::class)->findBy(['active' => true]),
        );
        $rendues = $source->sellableCapabilities();

        sort($attendues);
        sort($rendues);

        self::assertSame($attendues, $rendues);
        self::assertContains(
            'stock',
            $rendues,
            'une option a prix nul reste une option en vente : l’adaptateur ne doit pas en décider',
        );
    }
}
