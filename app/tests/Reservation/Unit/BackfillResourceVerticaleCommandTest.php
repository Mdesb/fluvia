<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Reservation\Command\BackfillResourceVerticaleCommand;
use PHPUnit\Framework\TestCase;

/**
 * La décision de `BackfillResourceVerticaleCommand::verticalePour` — pure, sans DB.
 *
 * Le codeType (EXACT, valable même sur un site mixte) prime ; sinon le métier unique de
 * l'établissement ; sinon `null` (on s'abstient plutôt que de poser une valeur fausse).
 */
final class BackfillResourceVerticaleCommandTest extends TestCase
{
    public function testLeCodeTypeSansAmbiguiteDecide(): void
    {
        self::assertSame('padel', BackfillResourceVerticaleCommand::verticalePour('terrain_padel', null));
        self::assertSame('padel', BackfillResourceVerticaleCommand::verticalePour('coach_padel', null));
        self::assertSame('piscine', BackfillResourceVerticaleCommand::verticalePour('bassin', null));
        self::assertSame('piscine', BackfillResourceVerticaleCommand::verticalePour('ligne_eau', null));
        self::assertSame('musee', BackfillResourceVerticaleCommand::verticalePour('exposition', null));
        self::assertSame('musee', BackfillResourceVerticaleCommand::verticalePour('visite_guidee', null));
    }

    public function testLeCodeTypePrimeSurLeMetierDeLEtablissement(): void
    {
        // Un bassin reste piscine même dans un établissement dont le métier unique serait « padel ».
        self::assertSame('piscine', BackfillResourceVerticaleCommand::verticalePour('bassin', 'padel'));
    }

    public function testUnCodeTypeGeneriqueRetombeSurLeMetierDeLEtablissement(): void
    {
        // « terrain », « salle », « personnel » ne désignent pas une verticale : on suit l'établissement.
        self::assertSame('piscine', BackfillResourceVerticaleCommand::verticalePour('salle', 'piscine'));
        self::assertSame('padel', BackfillResourceVerticaleCommand::verticalePour('terrain', 'padel'));
    }

    public function testSansCodeTypeConnuNiMetierUniqueOnSAbstient(): void
    {
        // Le témoin qui protège l'exclusion des codeType génériques : si « salle » entrait dans la
        // table, cette assertion tomberait.
        self::assertNull(BackfillResourceVerticaleCommand::verticalePour('salle', null));
        self::assertNull(BackfillResourceVerticaleCommand::verticalePour('', null));
        self::assertNull(BackfillResourceVerticaleCommand::verticalePour('inconnu', null));
    }
}
