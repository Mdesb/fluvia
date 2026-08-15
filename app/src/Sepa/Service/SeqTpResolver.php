<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\SeqTpSepa;

/**
 * Résout la `SeqTp` pain.008 d'une échéance pour un mandat donné (plan §3/§8) :
 * - `OOFF` si paiement unique (mandat non récurrent) — prioritaire.
 * - `FNAL` si l'échéance est la dernière d'un engagement à durée déterminée — prioritaire sur FRST/RCUR
 *   (après cette échéance, le mandat ne sera plus collecté dans le cadre de cet engagement).
 * - Sinon `FRST` si le mandat n'a jamais été collecté avec succès (`nbCollectesReussies == 0`),
 *   `RCUR` sinon.
 */
final class SeqTpResolver
{
    public function resoudre(MandatSepa $mandat, bool $derniereEcheanceEngagement, bool $paiementUnique): SeqTpSepa
    {
        if ($paiementUnique) {
            return SeqTpSepa::Ooff;
        }
        if ($derniereEcheanceEngagement) {
            return SeqTpSepa::Fnal;
        }

        return $mandat->getNbCollectesReussies() > 0 ? SeqTpSepa::Rcur : SeqTpSepa::Frst;
    }
}
