<?php

declare(strict_types=1);

namespace App\Sepa\Port;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\RemiseSepa;

/**
 * Port fourni PAR une verticale (Sport, Piscine…) AU module SEPA partagé (plan §3/§5) : chaque
 * verticale possède son propre échéancier métier (ex. `App\Membership\Entity\EcheanceSepa`) que le module
 * SEPA ne connaît pas. `App\Sepa\Service\GenerationRemiseHandler` reçoit une implémentation de ce port
 * en paramètre (jamais injectée globalement : plusieurs verticales peuvent coexister).
 */
interface EcheanceSepaSource
{
    /** @return list<EcheanceSepaDue> Échéances dues pour l'établissement à la date d'exécution donnée. */
    public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array;

    /**
     * Marque les échéances d'origine (identifiées par `EcheanceSepaDue::$referenceOrigine`) comme
     * collectées dans la remise donnée — permet à la verticale de mettre à jour son propre échéancier.
     *
     * @param list<string> $referencesOrigine
     */
    public function marquerCollectees(RemiseSepa $remise, array $referencesOrigine): void;
}
