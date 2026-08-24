<?php

declare(strict_types=1);

namespace App\Stay\Service;

use App\Stay\Entity\Stay;
use App\Stay\Entity\StayCharge;

/**
 * Lecture des lignes d'un séjour, isolée derrière une interface **pour deux raisons précises**.
 *
 * D'abord la testabilité : `StayChargeRecorder` porte la règle d'idempotence, qui est la règle la plus
 * coûteuse à se tromper de tout le module — facturer deux fois un client. Elle doit être couverte par
 * un test unitaire rapide, pas par une pile Docker qu'on renonce à lancer un vendredi soir.
 *
 * Ensuite le cloisonnement : le garde-fou C19 traque les `find()`/`findOneBy()` directs non confrontés
 * au périmètre. Concentrer ici les lectures donne **un seul endroit** à auditer, plutôt qu'un appel
 * Doctrine disséminé dans chaque service.
 */
interface StayChargeLookup
{
    /** La ligne déjà enregistrée pour ce fait, s'il a déjà été traité. */
    public function findBySource(Stay $stay, string $sourceEvent, string $sourceSubjectId): ?StayCharge;

    /**
     * Les montants des lignes du séjour, dans l'ordre où elles se sont produites.
     *
     * @return list<string>
     */
    public function amountsOf(Stay $stay): array;
}
