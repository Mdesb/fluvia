<?php

declare(strict_types=1);

namespace App\Tests\Crm\Unit;

use App\Crm\Enum\ActionConservation;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\CanalMouvementPmv;
use App\Crm\Enum\EtatConsentement;
use App\Crm\Enum\PorteeFusion;
use App\Crm\Enum\RoleBeneficiaire;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\StatutDemandeRgpd;
use App\Crm\Enum\StatutFamille;
use App\Crm\Enum\StatutJournalFusion;
use App\Crm\Enum\StatutPmv;
use App\Crm\Enum\TraitementSoldeResiduel;
use App\Crm\Enum\TypeClient;
use App\Crm\Enum\TypeDemandeRgpd;
use App\Crm\Enum\TypeMouvementPmv;
use PHPUnit\Framework\TestCase;

/** T1 — valeurs des enums CRM. */
final class EnumsTest extends TestCase
{
    public function testValeurs(): void
    {
        self::assertSame('physique', TypeClient::Physique->value);
        self::assertSame('morale', TypeClient::Morale->value);
        self::assertSame('actif', StatutClient::Actif->value);
        self::assertSame('fusionne', StatutClient::Fusionne->value);
        self::assertSame('payeur_et_beneficiaire', RoleBeneficiaire::PayeurEtBeneficiaire->value);
        self::assertSame('active', StatutFamille::Active->value);
        self::assertSame('expire', StatutPmv::Expire->value);
        self::assertSame('debit_vente', TypeMouvementPmv::DebitVente->value);
        self::assertSame('en_ligne', CanalMouvementPmv::EnLigne->value);
        self::assertSame('transforme_en_produit', TraitementSoldeResiduel::TransformeEnProduit->value);
        self::assertSame('courrier', CanalConsentement::Courrier->value);
        self::assertSame('a_renouveler', EtatConsentement::ARenouveler->value);
        self::assertSame('anonymisation', TypeDemandeRgpd::Anonymisation->value);
        self::assertSame('realisee', StatutDemandeRgpd::Realisee->value);
        self::assertSame('purge', ActionConservation::Purge->value);
        self::assertSame('famille', PorteeFusion::Famille->value);
        self::assertSame('defusionnee', StatutJournalFusion::Defusionnee->value);
    }
}
