<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Entity\Terminal;
use App\Acces\Security\TerminalAuthenticator;
use App\Acces\Security\TerminalUtilisateur;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Throttle de l'écriture `Terminal.dernierAppel` (plan-acces-terminal.md §3.1, revue sécurité) : au
 * plus 1 UPDATE / 30 s par terminal, pour éviter un UPDATE synchrone à chaque appel borne.
 */
final class TerminalAuthenticatorThrottleTest extends TestCase
{
    public function testEcritureIgnoreeSiDernierAppelRecent(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $terminal = new Terminal();
        $terminal->setDernierAppel(new \DateTimeImmutable('-5 seconds'));

        $authenticator = new TerminalAuthenticator($em, $connection);
        $token = new UsernamePasswordToken(new TerminalUtilisateur($terminal), 'terminal', ['ROLE_TERMINAL']);

        $authenticator->onAuthenticationSuccess(Request::create('/api/terminal/snapshot'), $token, 'terminal');
    }

    public function testEcritureDeclencheeSiDernierAppelAncienOuAbsent(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement');

        $terminal = new Terminal();
        $terminal->setDernierAppel(new \DateTimeImmutable('-1 hour'));

        $authenticator = new TerminalAuthenticator($em, $connection);
        $token = new UsernamePasswordToken(new TerminalUtilisateur($terminal), 'terminal', ['ROLE_TERMINAL']);

        $authenticator->onAuthenticationSuccess(Request::create('/api/terminal/snapshot'), $token, 'terminal');
    }

    public function testEcritureDeclencheeSiDernierAppelJamaisEnregistre(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement');

        $terminal = new Terminal();

        $authenticator = new TerminalAuthenticator($em, $connection);
        $token = new UsernamePasswordToken(new TerminalUtilisateur($terminal), 'terminal', ['ROLE_TERMINAL']);

        $authenticator->onAuthenticationSuccess(Request::create('/api/terminal/snapshot'), $token, 'terminal');
    }
}
