<?php

declare(strict_types=1);

namespace App\Tests\Social\Support;

use App\Social\Dto\PublicationOutcome;
use App\Social\Dto\PublicationRequest;
use App\Social\Enum\SocialNetwork;
use App\Social\Exception\SocialPublishingException;
use App\Social\Port\SocialPublisher;

/**
 * Adaptateur de publication pilotable, pour éprouver le handler sans réseau (SOC-2).
 *
 * Il sert autant à provoquer une réponse qu'à prouver une absence : `$calls` permet de vérifier qu'un
 * message rejoué **n'a pas** rappelé le réseau — ce qu'aucune assertion sur l'état final ne montrerait,
 * puisqu'une republication réussie laisse exactement le même état qu'une publication unique.
 */
final class FakePublisher implements SocialPublisher
{
    public int $calls = 0;

    /** @var list<PublicationRequest> */
    public array $received = [];

    private ?\Throwable $toThrow = null;

    private PublicationOutcome $outcome;

    public function __construct()
    {
        $this->outcome = new PublicationOutcome('remote-1', 'https://exemple.test/remote-1');
    }

    public function willThrow(\Throwable $e): self
    {
        $this->toThrow = $e;

        return $this;
    }

    public function willReturn(PublicationOutcome $outcome): self
    {
        $this->outcome = $outcome;

        return $this;
    }

    public function supports(SocialNetwork $network): bool
    {
        return true;
    }

    public function publish(PublicationRequest $request): PublicationOutcome
    {
        ++$this->calls;
        $this->received[] = $request;

        if ($this->toThrow !== null) {
            throw $this->toThrow;
        }

        return $this->outcome;
    }

    public static function unauthorized(): SocialPublishingException
    {
        return SocialPublishingException::permanent('unauthorized', 'Jeton refuse.');
    }

    public static function rateLimited(): SocialPublishingException
    {
        return SocialPublishingException::retryable('rate_limited', 'Quota depasse.');
    }
}
