<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutTerminal;
use App\Acces\Security\TerminalUtilisateur;
use App\Securite\Port\SupportAccessRightsInterface;
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
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ **CE TEST N'A PAS COMPILÉ PENDANT UN MOIS, ET PERSONNE NE L'A SU.**
 *
 * `CalculateurDroits` a reçu un second argument le 26/07 (`3c2dc57`, accès d'assistance de
 * l'éditeur) ; l'appel à un seul argument est resté ici. Le test levait donc un `ArgumentCountError`
 * avant d'exécuter la moindre assertion — un test de **sécurité** qui ne testait plus rien.
 *
 * Il n'a été vu que le 27/08, à la première exécution complète de la suite : 1 718 tests, six heures,
 * et une seule erreur — celle-ci.
 *
 * > **Un test qu'on ne lance jamais ne protège de rien, et coûte la confiance qu'on lui accorde.**
 *
 * C'est l'argument le plus solide pour rendre la suite lançable en minutes : ce n'est pas un confort
 * de développement, c'est ce qui décide si les tests de sécurité sont vivants ou décoratifs.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * **Le calculateur est bouchonné PERMISSIF, et c'est le point.** On lui fait accorder les quatre
 * codes que le test interroge. Si le refus dépendait d'un calculateur vide, ce bouchon le révélerait
 * ; comme le refus est structurel — `TerminalUtilisateur` n'est pas un `Utilisateur`, le vote
 * s'arrête avant tout calcul — il tient quand même. Un bouchon neutre aurait laissé passer une
 * régression qui ferait dépendre la frontière machine/humain du contenu des droits.
 */
final class PermissionVoterNonRegressionTest extends TestCase
{
    public function testTerminalNeDebloquePasPermUtilisateur(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);

        // Le port d'accès d'assistance, bouchonné au MAXIMUM de ce qu'il peut accorder.
        $accesAssistance = $this->createStub(SupportAccessRightsInterface::class);
        $accesAssistance->method('grantedCodes')->willReturn(
            ['acces.gerer', 'acces.superviser', 'acces.ingestion', 'acces.snapshot'],
        );

        $voter = new PermissionVoter(
            new ContexteEtablissement(new RequestStack(), $em),
            new CalculateurDroits($em, $accesAssistance),
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
