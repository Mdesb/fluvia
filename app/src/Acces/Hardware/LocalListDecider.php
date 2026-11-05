<?php

declare(strict_types=1);

namespace App\Acces\Hardware;

use App\Acces\Enum\CodeMotifRefus;

/**
 * La décision qu'une borne prend SEULE, sur sa liste locale, quand le serveur est injoignable (#337).
 *
 * Elle lit une entrée de `GET /terminal/snapshot` (forme `EntreeSnapshotDto::toArray()`) et n'en
 * applique que ce que la liste permet de savoir : support connu, non révoqué, porte éligible, dans
 * sa fenêtre de validité, crédit suffisant pour le groupe. Le reste (anti-passback, horaires du site,
 * jauge) n'est tranché qu'au rejeu par le serveur, qui garde le dernier mot.
 *
 * Écrite ici pour deux usages : le terminal simulé de Fluvia, et la référence remise à
 * l'intégrateur — une borne qui décide autrement que cette fonction décide mal.
 */
final class LocalListDecider
{
    /**
     * @param array<string, mixed>|null $entry entrée de la liste locale pour cet identifiant, `null` si absente
     */
    public function decide(?array $entry, string $equipmentId, \DateTimeImmutable $at): GateDecision
    {
        if ($entry === null) {
            return GateDecision::deny(CodeMotifRefus::DroitInvalide->value, 'Support inconnu de la liste locale.');
        }

        if (($entry['revoque'] ?? true) === true) {
            return GateDecision::deny(CodeMotifRefus::SupportBloque->value, 'Support révoqué.');
        }

        if (!\in_array($equipmentId, (array) ($entry['portesEligibles'] ?? []), true)) {
            return GateDecision::deny(CodeMotifRefus::ZoneNonAutorisee->value, 'Ce titre n\'ouvre pas cette porte.');
        }

        $start = isset($entry['validiteDebut']) ? new \DateTimeImmutable((string) $entry['validiteDebut']) : null;
        $end = isset($entry['validiteFin']) ? new \DateTimeImmutable((string) $entry['validiteFin']) : null;
        if (($start !== null && $at < $start) || ($end !== null && $at > $end)) {
            return GateDecision::deny(CodeMotifRefus::HorsMarge->value, 'Hors de la période de validité.');
        }

        $headcount = max(1, (int) ($entry['headcount'] ?? 1));
        $remaining = $entry['compostagesRestants'] ?? null;
        if ($remaining !== null && (int) $remaining < $headcount) {
            return GateDecision::deny(CodeMotifRefus::CreditEpuise->value, 'Crédit insuffisant.');
        }

        return GateDecision::grant($headcount, $headcount > 1 ? sprintf('Groupe de %d personnes.', $headcount) : 'Accès autorisé.');
    }
}
