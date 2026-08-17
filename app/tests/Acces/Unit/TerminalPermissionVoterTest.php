<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutTerminal;
use App\Acces\Security\TerminalPermissionVoter;
use App\Acces\Security\TerminalUtilisateur;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * `TerminalPermissionVoter` (§3.2 du plan) : permissions fixes uniquement (`acces.ingestion`,
 * `acces.snapshot`), jamais `acces.gerer`/`acces.superviser` ; un `Terminal` révoqué n'obtient plus rien.
 */
final class TerminalPermissionVoterTest extends TestCase
{
    public function testPermissionsFixesUniquement(): void
    {
        $voter = new TerminalPermissionVoter();
        $terminal = (new Terminal())->setStatut(StatutTerminal::Actif);
        $token = new UsernamePasswordToken(new TerminalUtilisateur($terminal), 'terminal', ['ROLE_TERMINAL']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, 'acces.ingestion', ['PERM_TERMINAL']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, 'acces.snapshot', ['PERM_TERMINAL']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'acces.gerer', ['PERM_TERMINAL']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'acces.superviser', ['PERM_TERMINAL']));
    }

    public function testTerminalRevoqueNObtientPlusRien(): void
    {
        $voter = new TerminalPermissionVoter();
        $terminal = (new Terminal())->setStatut(StatutTerminal::Revoque);
        $token = new UsernamePasswordToken(new TerminalUtilisateur($terminal), 'terminal', ['ROLE_TERMINAL']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'acces.ingestion', ['PERM_TERMINAL']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'acces.snapshot', ['PERM_TERMINAL']));
    }

    public function testAttributNonPermTerminalIgnore(): void
    {
        $voter = new TerminalPermissionVoter();
        $terminal = (new Terminal())->setStatut(StatutTerminal::Actif);
        $token = new UsernamePasswordToken(new TerminalUtilisateur($terminal), 'terminal', ['ROLE_TERMINAL']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, 'acces.ingestion', ['PERM']));
    }
}
