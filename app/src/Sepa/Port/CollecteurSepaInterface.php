<?php

declare(strict_types=1);

namespace App\Sepa\Port;

use App\Sepa\Entity\RemiseSepa;

/**
 * Port de transmission bancaire d'une remise SEPA déjà générée (pain.008 prêt, §2.1/§4 du plan).
 * Générique — plus aucune dépendance à une verticale (contrairement à l'ancien port Sport, qui portait
 * aussi la génération et les retours). L'adaptateur par défaut est un stub : aucune remise bancaire
 * réelle (EBICS/SFTP) n'est effectuée par ce lot (§9 du plan, hors périmètre).
 */
interface CollecteurSepaInterface
{
    /** Transmet une remise déjà générée (XML pain.008 renseigné) ; retourne une référence de transmission. */
    public function transmettre(RemiseSepa $remise): string;
}
