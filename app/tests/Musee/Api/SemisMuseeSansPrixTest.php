<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Tests\Musee\MuseeApiTestCase;
use App\Tests\Offre\SemisSansPrixTrait;

/** Le semis du musée, sous le filet commun. Voir `SemisSansPrixTrait` pour le pourquoi. */
final class SemisMuseeSansPrixTest extends MuseeApiTestCase
{
    use SemisSansPrixTrait;
}
