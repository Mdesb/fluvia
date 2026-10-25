<?php

declare(strict_types=1);

namespace App\Tests\Integrations\Integration;

use App\DataFixtures\SocleFixtures;
use App\Integrations\Entity\OutboundEndpoint;
use App\Integrations\Enum\EndpointKind;
use App\Integrations\EventSubscriber\ForwardDomainEvents;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Tests\Platform\ModuleSwitch;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recentrage, PR 2 (d) — les connecteurs ne transfèrent plus les événements d'un établissement où le
 * module est éteint.
 *
 * L'adresse de la destination est volontairement illisible : un envoi tenté s'arrête alors avant le
 * réseau et laisse sa trace (`dernierEchec`). Éteint : aucune tentative, aucune trace. Allumé : la
 * tentative a lieu (témoin).
 */
final class SwitchedOffForwardingTest extends SocleApiTestCase
{
    use ModuleSwitch;

    public function testEventsOfAnEstablishmentWithConnectorsOffAreNotForwarded(): void
    {
        foreach ([false, true] as $on) {
            $this->switchModule('connecteurs', $on, SocleFixtures::ETAB_A_NOM);
            $endpoint = $this->endpoint();

            /** @var EventBus $bus */
            $bus = static::getContainer()->get(EventBus::class);
            $bus->publish(new DomainEvent(
                'payment.failed',
                new EventTenant($this->establishmentA()->getId()),
                new EventSubject('Paiement', (string) Uuid::v4()),
                ['amount_cents' => 4200],
            ));
            static::getContainer()->get(ForwardDomainEvents::class)->vider();

            $this->em()->clear();
            $reread = $this->em()->getRepository(OutboundEndpoint::class)->find($endpoint->getId());
            self::assertInstanceOf(OutboundEndpoint::class, $reread);
            if ($on) {
                self::assertNotNull($reread->getDernierEchec(), 'témoin : allumé, l\'envoi est tenté');
            } else {
                self::assertNull($reread->getDernierEchec(), 'éteint, rien n\'est tenté');
            }
        }
    }

    private function endpoint(): OutboundEndpoint
    {
        $endpoint = (new OutboundEndpoint())
            ->setEstablishment($this->establishmentA())
            ->setKind(EndpointKind::Generique)
            ->setLibelle('Témoin recentrage')
            ->setUrlChiffree('illisible')
            ->setHote('exemple.invalid')
            ->setEvenements(['payment.failed'])
            ->setActif(true);
        $this->em()->persist($endpoint);
        $this->em()->flush();

        return $endpoint;
    }

    private function establishmentA(): Etablissement
    {
        $establishment = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $establishment);

        return $establishment;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
