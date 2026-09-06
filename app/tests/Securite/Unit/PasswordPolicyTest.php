<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Securite\Service\PasswordPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * La règle unique du mot de passe (audit 06/09, constat 7) : ce qu'elle refuse, et ce qu'elle laisse
 * passer — sans le second, une règle qui refuserait tout passerait le premier.
 */
final class PasswordPolicyTest extends TestCase
{
    public function testTroisCaracteresSontRefuses(): void
    {
        self::assertNotNull((new PasswordPolicy())->violation('aaa'));
    }

    public function testOnzeCaracteresSontRefusesDouzePassent(): void
    {
        $policy = new PasswordPolicy();

        self::assertNotNull($policy->violation('abcdefghijk'), '11 caractères : refusé');
        self::assertNull($policy->violation('abcdefghijkl'), '12 caractères : accepté');
    }

    public function testLAdresseEmailNEstPasUnMotDePasse(): void
    {
        self::assertNotNull((new PasswordPolicy())->violation('camille.martin@example.test', 'Camille.Martin@example.test'));
    }

    public function testLaLongueurSeCompteEnCaracteresPasEnOctets(): void
    {
        // 12 caractères accentués, 24 octets : ce sont les caractères qui comptent, dans les deux sens.
        self::assertNull((new PasswordPolicy())->violation('éèàùçôîïüëäö'));
        self::assertNotNull((new PasswordPolicy())->violation('éèàùçôîïüëä'), '11 caractères accentués : refusé, même sur 22 octets');
    }

    public function testAssertAcceptableLeveAvecLeMotifEnClair(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('12 caractères au minimum');

        (new PasswordPolicy())->assertAcceptable('aaa');
    }
}
