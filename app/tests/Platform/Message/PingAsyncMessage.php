<?php

declare(strict_types=1);

namespace App\Tests\Platform\Message;

use App\Platform\Message\AsyncMessage;

/**
 * Message minimal servant à vérifier le routage asynchrone (D7-bis).
 *
 * Volontairement sans gestionnaire : un message envoyé vers un transport n'est pas exécuté dans la
 * requête, et c'est exactement la propriété qu'on teste.
 */
final class PingAsyncMessage implements AsyncMessage
{
    public function __construct(public readonly string $charge = 'ping')
    {
    }
}
