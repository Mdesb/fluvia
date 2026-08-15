<?php

declare(strict_types=1);

namespace App\Sepa\Port;

use App\Sepa\Dto\RetourSepaDto;

/**
 * Port d'ingestion des retours bancaires (rejets, §2.1/§4 du plan). Aucun parser pain.002/CAMT.054
 * réel n'est fourni (le client n'a pas transmis de fichier retour, §9 du plan) — l'adaptateur par
 * défaut est un stub ; en attendant, une saisie/simulation manuelle (`POST /sepa/rejets`) alimente les
 * `RejetSepa` depuis le tableau de bord.
 */
interface RetourSepaInterface
{
    /** @return list<RetourSepaDto> Relève les retours normalisés (rejets) depuis une date. */
    public function relever(\DateTimeImmutable $depuis): array;
}
