<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Type de qualification (RG-PERSO-02) — liste ouverte ⚠ hypothèse spec §4.2/§5. */
enum TypeQualification: string
{
    case Mns = 'MNS';
    case Bnssa = 'BNSSA';
    case Beesan = 'BEESAN';
    case Bafa = 'BAFA';
    case Bpjeps = 'BPJEPS';
    case Autre = 'autre';
}
