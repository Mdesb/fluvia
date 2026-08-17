<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Unit;

use App\Personnel\Entity\Employe;
use App\Personnel\Security\EmployeSoiVoter;
use App\Securite\Entity\Utilisateur;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Un Employé sans Utilisateur (§3 spec) n'a par construction aucun accès `*_soi`.
 */
final class EmployeSoiVoterTest extends TestCase
{
    public function testEmployeSansUtilisateurNaAucunAccesSoi(): void
    {
        $voter = new EmployeSoiVoter();
        $employe = new Employe(); // pas de setUtilisateur().

        $utilisateur = (new Utilisateur())->setEmail('quidam@itcotation.com')->setNom('Quidam');
        $token = new UsernamePasswordToken($utilisateur, 'main', ['ROLE_USER']);

        $resultat = $voter->vote($token, $employe, [EmployeSoiVoter::ATTRIBUTE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $resultat);
    }

    public function testEmployeAvecUtilisateurCorrespondantEstAutorise(): void
    {
        $voter = new EmployeSoiVoter();
        $utilisateur = (new Utilisateur())->setEmail('soi@itcotation.com')->setNom('Soi');
        $employe = (new Employe())->setUtilisateur($utilisateur);

        $token = new UsernamePasswordToken($utilisateur, 'main', ['ROLE_USER']);

        $resultat = $voter->vote($token, $employe, [EmployeSoiVoter::ATTRIBUTE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $resultat);
    }
}
