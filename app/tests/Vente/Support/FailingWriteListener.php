<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

use Doctrine\ORM\Event\PostPersistEventArgs;

/**
 * Fait échouer l'écriture d'une classe d'entité, PENDANT le `flush()` : son `INSERT` est parti, puis
 * l'exception tombe. C'est l'échec réel qu'on veut — l'`EntityManager` se ferme, la transaction est
 * annulée — et non un refus avant écriture. Désarmé par défaut ; câblé en `when@test`.
 */
final class FailingWriteListener
{
    /** @var class-string|null */
    private ?string $failOn = null;

    /** @param class-string|null $class */
    public function failOn(?string $class): void
    {
        $this->failOn = $class;
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        if ($this->failOn !== null && is_a($args->getObject(), $this->failOn)) {
            throw new \RuntimeException('Écriture forcée en échec (test) : ' . $this->failOn);
        }
    }
}
