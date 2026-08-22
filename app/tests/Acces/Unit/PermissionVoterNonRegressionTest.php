<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutTerminal;
use App\Acces\Security\TerminalUtilisateur;
use App\Securite\Security\PermissionVoter;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Non-régression du cloisonnement humain/machine (plan-acces-terminal.md §3.2, revue sécurité) : un
 * jeton `Terminal` (`TerminalUtilisateur`) ne débloque jamais aucun attribut `PERM` humain
 * (`App\Securite\Security\PermissionVoter`) — seul `PERM_TERMINAL` (`TerminalPermissionVoter`) lui est
 * accessible, et seulement pour ses deux capacités fixes (`acces.ingestion`/`acces.snapshot`).
 */
final class PermissionVoterNonRegressionTest extends TestCase
{
    public function testTerminalNeDebloquePasPermUtilisateur(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $voter = new PermissionVoter(
            new ContexteEtablissement(new RequestStack(), $em),
            new CalculateurDroits($em),
        );

        $terminal = (new Terminal())->setStatut(StatutTerminal::Actif);
        $token = new UsernamePasswordToken(new TerminalUtilisateur($terminal), 'terminal', ['ROLE_TERMINAL']);

        // Un `TerminalUtilisateur` n'est pas une instance de `App\Securite\Entity\Utilisateur` :
        // `PermissionVoter::voteOnAttribute()` doit refuser systématiquement, quel que soit le module.
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'acces.gerer', ['PERM']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'acces.superviser', ['PERM']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'acces.ingestion', ['PERM']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 'acces.snapshot', ['PERM']));
    }
}
