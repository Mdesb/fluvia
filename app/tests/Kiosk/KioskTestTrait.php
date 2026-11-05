<?php

declare(strict_types=1);

namespace App\Tests\Kiosk;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Kiosk\DataFixtures\KioskFixtures;
use App\Kiosk\Entity\Kiosk;
use App\Kiosk\Entity\KioskOrder;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutTPE;
use App\Vente\Tpe\TpeMock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le parcours d'un APPAREIL de borne dans les tests : appairage par l'API du personnel, puis requêtes
 * avec le seul jeton d'appareil. Un même client HTTP porte les deux (`disableReboot`), pour que le
 * terminal simulé programmé par le test soit celui que la borne sollicite.
 */
trait KioskTestTrait
{
    protected function loadKioskFixtures(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        static::getContainer()->get(KioskFixtures::class)->load($em);
        self::ensureKernelShutdown();
    }

    protected function kioskEm(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    protected function kiosk(string $label): Kiosk
    {
        $kiosk = $this->kioskEm()->getRepository(Kiosk::class)->findOneBy(['label' => $label]);
        self::assertInstanceOf(Kiosk::class, $kiosk, sprintf('Borne « %s » introuvable.', $label));

        return $kiosk;
    }

    /**
     * Appaire la borne : un agent (son en-tête `$staff`, établissement compris) émet le code, l'appareil
     * l'échange contre son jeton.
     *
     * @param array<string, mixed> $staff
     */
    protected function pairDevice(Client $client, array $staff, string $label): string
    {
        $id = (string) $this->kiosk($label)->getId();
        $code = $client->request('POST', '/api/kiosks/' . $id . '/pairing-code', $staff + ['json' => []]);
        self::assertResponseStatusCodeSame(201, (string) $code->getContent(false));

        $pair = $client->request('POST', '/api/kiosk/pair', ['json' => ['code' => $code->toArray()['code']]]);
        self::assertResponseStatusCodeSame(201, (string) $pair->getContent(false));

        return $pair->toArray()['token'];
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function device(Client $client, string $token, string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $options = ['auth_bearer' => $token, 'headers' => $headers];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $response = $client->request($method, '/api/kiosk' . $path, $options);
        $content = (string) $response->getContent(false);

        return [$response->getStatusCode(), $content !== '' ? (array) json_decode($content, true) : []];
    }

    /** @param array<string, mixed> $config */
    protected function offerOf(array $config, string $productLabel, string $tariff): string
    {
        foreach ($config['offers'] as $offer) {
            if ($offer['label'] === $productLabel && $offer['tariffLabel'] === $tariff) {
                return $offer['offer'];
            }
        }
        self::fail(sprintf('Offre « %s / %s » absente de la borne.', $productLabel, $tariff));
    }

    protected function forceTerminal(?StatutTPE $status): void
    {
        $tpe = static::getContainer()->get(TpeMock::class);
        self::assertInstanceOf(TpeMock::class, $tpe);
        $tpe->forcerIssue($status);
    }

    protected function sale(string $id): Vente
    {
        $em = $this->kioskEm();
        $em->clear();
        $sale = $em->find(Vente::class, Uuid::fromString($id));
        self::assertInstanceOf(Vente::class, $sale);

        return $sale;
    }

    protected function order(string $saleId): KioskOrder
    {
        $order = $this->kioskEm()->getRepository(KioskOrder::class)->findOneBy(['sale' => $this->sale($saleId)]);
        self::assertInstanceOf(KioskOrder::class, $order);

        return $order;
    }
}
