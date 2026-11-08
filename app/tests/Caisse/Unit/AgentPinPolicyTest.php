<?php

declare(strict_types=1);

namespace App\Tests\Caisse\Unit;

use App\Caisse\Service\AgentPinOutcome;
use App\Caisse\Service\AgentPinPolicy;
use App\Securite\Entity\Utilisateur;
use PHPUnit\Framework\TestCase;

/**
 * La règle du code agent (#346), sans base : un code faux est refusé, le juste accepté, cinq erreurs
 * bloquent même le juste, et un compte sans code n'accepte rien.
 */
final class AgentPinPolicyTest extends TestCase
{
    public function testUnCodeFauxEstRefuseEtLeJusteAccepte(): void
    {
        $agent = (new Utilisateur())->setCashPinHash(AgentPinPolicy::hash('2468'));

        self::assertSame(AgentPinOutcome::Rejected, AgentPinPolicy::check($agent, '1357'));
        self::assertSame(AgentPinOutcome::Rejected, AgentPinPolicy::check($agent, 'CODE-REGIE-2026'));
        self::assertSame(2, $agent->getCashPinFailures());

        self::assertSame(AgentPinOutcome::Accepted, AgentPinPolicy::check($agent, '2468'));
        self::assertSame(0, $agent->getCashPinFailures());
    }

    public function testCinqErreursBloquentMemeLeCodeJuste(): void
    {
        $agent = (new Utilisateur())->setCashPinHash(AgentPinPolicy::hash('2468'));
        for ($i = 1; $i < AgentPinPolicy::MAX_FAILURES; ++$i) {
            self::assertSame(AgentPinOutcome::Rejected, AgentPinPolicy::check($agent, '0000'));
        }
        self::assertSame(AgentPinOutcome::Locked, AgentPinPolicy::check($agent, '0000'));
        self::assertSame(AgentPinOutcome::Locked, AgentPinPolicy::check($agent, '2468'));
        self::assertSame(0, AgentPinPolicy::remainingAttempts($agent));
    }

    public function testUnCompteSansCodeNAccepteRien(): void
    {
        $agent = new Utilisateur();
        self::assertSame(AgentPinOutcome::Undefined, AgentPinPolicy::check($agent, '0000'));
        self::assertSame(AgentPinOutcome::Undefined, AgentPinPolicy::check($agent, ''));
    }

    public function testFormatQuatreAHuitChiffres(): void
    {
        self::assertTrue(AgentPinPolicy::isWellFormed('1234'));
        self::assertTrue(AgentPinPolicy::isWellFormed('12345678'));
        self::assertFalse(AgentPinPolicy::isWellFormed('123'));
        self::assertFalse(AgentPinPolicy::isWellFormed('123456789'));
        self::assertFalse(AgentPinPolicy::isWellFormed('12a4'));
        self::assertFalse(AgentPinPolicy::isWellFormed(''));

        $this->expectException(\InvalidArgumentException::class);
        AgentPinPolicy::hash('12');
    }

    public function testLeHacheNEstPasLeCode(): void
    {
        $hash = AgentPinPolicy::hash('2468');
        self::assertNotSame('2468', $hash);
        self::assertStringNotContainsString('2468', $hash);
    }
}
