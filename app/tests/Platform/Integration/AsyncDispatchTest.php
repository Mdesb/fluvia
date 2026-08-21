<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\Tests\Platform\Message\PingAsyncMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * D7-bis — un message porteur du marqueur part vers le transport, il ne s'exécute pas dans la requête.
 *
 * C'est la seule propriété qui compte vraiment, et elle est invisible autrement : si le routage
 * tombait, les messages continueraient de « fonctionner » — en synchrone. On ne s'en apercevrait
 * qu'en production, sous la forme d'un ralentissement inexplicable puis de verrous de base, parce
 * qu'un appel d'API externe se serait mis à tenir la transaction d'un clic utilisateur.
 */
final class AsyncDispatchTest extends KernelTestCase
{
    public function testUnMessageMarqueEstEnvoyeVersLeTransportAsynchrone(): void
    {
        self::bootKernel();

        $bus = static::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $bus->dispatch(new PingAsyncMessage('bonjour'));

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport, 'le transport de test doit être en mémoire');

        $envois = $transport->getSent();
        self::assertCount(1, $envois, 'le message doit partir vers le transport, pas être traité sur place');

        $message = $envois[0]->getMessage();
        self::assertInstanceOf(PingAsyncMessage::class, $message);
        self::assertSame('bonjour', $message->charge);
    }

    /** Le transport d'échec doit exister : sans lui, un message qui échoue disparaît en silence. */
    public function testLeTransportDechecExiste(): void
    {
        self::bootKernel();

        self::assertTrue(
            static::getContainer()->has('messenger.transport.failed'),
            'un transport d\'échec est indispensable : l\'asynchrone déplace les erreurs hors de vue',
        );
    }
}
