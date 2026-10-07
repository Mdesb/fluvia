<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Scheduling\ScheduleCatalog;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Entity\PartnerWebhookDelivery;
use App\PublicApi\Entity\PartnerWebhookSubscription;
use App\Tests\PublicApi\PublicApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * L'alerte des webhooks partenaires (spec API partenaire v1, §3.5) : silencieuse quand tout va bien,
 * en échec quand une livraison attend depuis plus de 15 minutes — et inscrite au catalogue que
 * l'ordonnanceur exécute.
 */
final class AlertPartnerWebhooksCommandTest extends PublicApiTestCase
{
    public function testLAlerteSeTaitPuisSignaleUneLivraisonEnAttenteDepuisPlusDe15Minutes(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]), 'témoin : rien en attente, rien à signaler');

        $delivery = $this->pendingDelivery();
        self::assertSame(0, $tester->execute([]), 'une livraison toute neuve n’est pas une panne');

        $this->em()->getConnection()->executeStatement(
            'UPDATE public_api_webhook_delivery SET created_at = :old WHERE id = :id',
            ['old' => (new \DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s'), 'id' => $delivery->getId()->toBinary()],
        );
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('1 livraison(s) en attente depuis plus de 15 minutes', $tester->getDisplay());
    }

    /** Une commande hors du catalogue ne tourne jamais, en silence : elle y est, et sûre au premier passage. */
    public function testLAlerteEstAuCatalogueDeLOrdonnanceur(): void
    {
        $task = static::getContainer()->get(ScheduleCatalog::class)->find('public-api:webhooks:alerter');
        self::assertNotNull($task);
        self::assertTrue($task->safeOnFirstRun);
        self::assertStringContainsString('public-api:webhooks:alerter', (string) file_get_contents(\dirname(__DIR__, 4).'/infra/ordonnanceur.sh'));
    }

    private function tester(): CommandTester
    {
        $application = new Application(static::bootKernel());

        return new CommandTester($application->find('public-api:webhooks:alerter'));
    }

    private function pendingDelivery(): PartnerWebhookDelivery
    {
        $em = $this->em();
        $partner = (new PartnerApplication())->setName('Partenaire')->setContactEmail('p@example.test');
        $subscription = (new PartnerWebhookSubscription($partner))->setUrl('x', 'partenaire.example')->setEvents(['booking.cancelled']);
        $establishment = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $establishment);
        $delivery = new PartnerWebhookDelivery($subscription, $establishment, Uuid::v7(), 'booking.cancelled', '{}');
        foreach ([$partner, $subscription->setEncryptedSecret('x'), $delivery] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $delivery;
    }
}
