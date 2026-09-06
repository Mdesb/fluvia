<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Paiement\PayFipBoutiqueAdapter;
use App\Boutique\Paiement\PspCbStubAdapter;
use App\Boutique\Paiement\SelecteurPaiementEnLigne;
use App\Boutique\Paiement\StubPaymentReceiptSigner;
use App\Compta\Adapter\PayFipStubAdapter;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use PHPUnit\Framework\TestCase;

/**
 * Paiement en ligne commuté (US-L8-07, RG-M3-11, CA-9) : `RegieDirecte` → PayFiP (enveloppe
 * `PayFipInterface` M6, inchangé) ; `Dsp`/`GroupePrive` → PSP CB (même adaptateur privé, changement
 * de profil bascule sans ressaisie).
 */
final class SelecteurPaiementEnLigneTest extends TestCase
{
    private function selecteur(): SelecteurPaiementEnLigne
    {
        // Les bouchons signent leurs recus (audit 06/09, constat 1) : un secret de test suffit ici,
        // ce fichier ne teste que la commutation.
        $signer = new StubPaymentReceiptSigner("secret-de-test");

        return new SelecteurPaiementEnLigne([
            new PayFipBoutiqueAdapter(new PayFipStubAdapter(), $signer),
            new PspCbStubAdapter($signer),
        ]);
    }

    public function testRegieDirecteResoutPayFip(): void
    {
        $profil = (new ProfilExploitant())->setType(TypeExploitant::RegieDirecte);
        $adaptateur = $this->selecteur()->pour($profil);
        self::assertInstanceOf(PayFipBoutiqueAdapter::class, $adaptateur);
    }

    public function testDspEtGroupePriveResolventLeMemeAdaptateurPsp(): void
    {
        $selecteur = $this->selecteur();

        $profilDsp = (new ProfilExploitant())->setType(TypeExploitant::Dsp);
        $profilPrive = (new ProfilExploitant())->setType(TypeExploitant::GroupePrive);

        self::assertInstanceOf(PspCbStubAdapter::class, $selecteur->pour($profilDsp));
        self::assertInstanceOf(PspCbStubAdapter::class, $selecteur->pour($profilPrive));
    }

    public function testChangerDeProfilBasculeLeMoyenSansRessaisie(): void
    {
        $selecteur = $this->selecteur();
        $profil = (new ProfilExploitant())->setType(TypeExploitant::RegieDirecte);
        self::assertInstanceOf(PayFipBoutiqueAdapter::class, $selecteur->pour($profil));

        $profil->setType(TypeExploitant::GroupePrive);
        self::assertInstanceOf(PspCbStubAdapter::class, $selecteur->pour($profil));
    }
}
