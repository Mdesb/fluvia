<?php

declare(strict_types=1);

namespace App\Acces\Hardware\Simulated;

/**
 * Les trois appels d'une borne au contrat terminal de Fluvia (`/api/terminal/*`), sans HTTP (#337).
 *
 * Le terminal simulé ne sait rien du transport : en test il passe par le vrai noyau HTTP, et un
 * outil de démonstration pourrait passer par un client réseau. Les formes échangées sont exactement
 * celles du contrat publié (OpenAPI de `SnapshotTerminal` et `SynchronisationAcces`).
 */
interface TerminalTransport
{
    /**
     * `GET /terminal/snapshot?depuis=…` : liste complète (0) ou delta depuis une version.
     *
     * @return array{versionCourante: int, entrees: list<array<string, mixed>>}
     */
    public function snapshot(int $since): array;

    /**
     * `POST /terminal/passages` : décision en ligne.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    public function validate(array $event): array;

    /**
     * `POST /terminal/passages/lot` : remontée des passages décidés hors connexion.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    public function pushBatch(array $events): array;
}
