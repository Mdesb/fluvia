<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Offre\SemisSansPrixTrait;

/** Le semis de la boutique, sous le meme filet. Voir `SemisSansPrixTrait` pour le pourquoi. */
final class SemisBoutiqueSansPrixTest extends BoutiqueApiTestCase
{
    use SemisSansPrixTrait;
}
