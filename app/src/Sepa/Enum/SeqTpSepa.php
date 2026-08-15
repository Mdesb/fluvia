<?php

declare(strict_types=1);

namespace App\Sepa\Enum;

/**
 * Séquence de prélèvement SEPA (`PmtTpInf/SeqTp` pain.008, plan §1/§3). Résolue par `SeqTpResolver` :
 * `Frst` = 1er prélèvement d'un mandat, `Rcur` = prélèvements suivants, `Fnal` = dernière échéance
 * d'un engagement à durée déterminée, `Ooff` = paiement unique (mandat non récurrent).
 */
enum SeqTpSepa: string
{
    case Frst = 'FRST';
    case Rcur = 'RCUR';
    case Fnal = 'FNAL';
    case Ooff = 'OOFF';
}
