<?php

declare(strict_types=1);

namespace App\Support\Service;

use App\Support\Enum\ResultatImport;

/** Résumé d'une exécution de `ImporteurAideService::executer()` (CLI + `POST /support/import/executer`). */
final class ResumeImportAide
{
    /** @var list<array{fichier: string, resultat: string, message: ?string}> */
    public array $lignes = [];

    public int $cree = 0;
    public int $maj = 0;
    public int $inchange = 0;
    public int $erreur = 0;

    public function ajouter(string $fichier, ResultatImport $resultat, ?string $message = null): void
    {
        $this->lignes[] = ['fichier' => $fichier, 'resultat' => $resultat->value, 'message' => $message];

        match ($resultat) {
            ResultatImport::Cree => $this->cree++,
            ResultatImport::Maj => $this->maj++,
            ResultatImport::Inchange => $this->inchange++,
            ResultatImport::Erreur => $this->erreur++,
        };
    }

    public function total(): int
    {
        return $this->cree + $this->maj + $this->inchange + $this->erreur;
    }
}
