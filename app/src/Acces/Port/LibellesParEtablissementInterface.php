<?php

declare(strict_types=1);

namespace App\Acces\Port;

use App\Acces\Enum\CodeMessageAffichage;
use App\Organisation\Entity\Etablissement;

/**
 * Point d'extension **futur** (spec-acces-terminal.md §4.5, non actée) : personnalisation du libellé
 * d'un `CodeMessageAffichage` par établissement/langue. Aucune implémentation dans ce lot — injection
 * nullable dans `App\Acces\Service\CatalogueMessageAffichage` (stub `null` = libellés par défaut
 * uniquement), pour ne pas bloquer une évolution ultérieure sans re-toucher l'appelant.
 */
interface LibellesParEtablissementInterface
{
    public function libellePour(CodeMessageAffichage $code, Etablissement $etablissement): ?string;
}
