<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use PHPUnit\Framework\TestCase;

/**
 * §2.1 plan-backoffice.md — `isActif()`/`setActif()` restent équivalents à `statut` (compatibilité
 * ascendante fixtures socle/CRM, aucun fichier de fixtures à modifier).
 */
final class CompatStatutActifTest extends TestCase
{
    public function testSetActifTrueEquivautStatutActif(): void
    {
        $utilisateur = new Utilisateur();
        $utilisateur->setActif(true);

        self::assertSame(StatutUtilisateur::Actif, $utilisateur->getStatut());
        self::assertTrue($utilisateur->isActif());
    }

    public function testSetActifFalseEquivautStatutSuspendu(): void
    {
        $utilisateur = new Utilisateur();
        $utilisateur->setActif(false);

        self::assertSame(StatutUtilisateur::Suspendu, $utilisateur->getStatut());
        self::assertFalse($utilisateur->isActif());
    }

    public function testStatutInviteParDefaut(): void
    {
        $utilisateur = new Utilisateur();

        self::assertSame(StatutUtilisateur::Invite, $utilisateur->getStatut());
        self::assertFalse($utilisateur->isActif());
    }

    public function testSetStatutActifRendIsActifVrai(): void
    {
        $utilisateur = new Utilisateur();
        $utilisateur->setStatut(StatutUtilisateur::Actif);

        self::assertTrue($utilisateur->isActif());
    }
}
