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
use App\Boutique\Paiement\PayFipOnlinePaymentAdapter;
use App\Boutique\Paiement\Stripe\StripeCheckoutAdapter;
use App\Compta\PayFip\PayFipConfiguration;
use App\Compta\PayFip\PayFipSoapClient;
use App\Compta\Service\TraiterRetourPayFipHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

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

    /**
     * #339 — un vrai prestataire configuré passe devant le bouchon de son régime ; non configuré, il
     * s'efface, et le bouchon (qui refuse lui-même de servir hors préproduction) répond comme avant.
     */
    public function testUnPrestataireReelConfigurePasseDevantLeBouchonDeSonRegime(): void
    {
        $signer = new StubPaymentReceiptSigner('secret-de-test');
        $payFipReel = fn (?string $numcli): PayFipOnlinePaymentAdapter => new PayFipOnlinePaymentAdapter(
            new PayFipConfiguration($numcli, 'W', 'https://boutique.example.test/notif'),
            new PayFipSoapClient(new MockHttpClient(), new PayFipConfiguration()),
            new TraiterRetourPayFipHandler($this->createStub(EntityManagerInterface::class)),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
        $stripe = static fn (?string $cle): StripeCheckoutAdapter => new StripeCheckoutAdapter(new MockHttpClient(), new NullLogger(), $cle);
        $regie = (new ProfilExploitant())->setType(TypeExploitant::RegieDirecte);
        $dsp = (new ProfilExploitant())->setType(TypeExploitant::Dsp);

        // L'ordre d'enregistrement ne décide de rien : bouchons d'abord, réels ensuite.
        $configures = new SelecteurPaiementEnLigne([
            new PayFipBoutiqueAdapter(new PayFipStubAdapter(), $signer),
            new PspCbStubAdapter($signer),
            $payFipReel('999123'),
            $stripe('sk_test_' . 'CLE0000'),
        ]);
        self::assertInstanceOf(PayFipOnlinePaymentAdapter::class, $configures->pour($regie));
        self::assertInstanceOf(StripeCheckoutAdapter::class, $configures->pour($dsp));
        self::assertInstanceOf(PayFipOnlinePaymentAdapter::class, $configures->pourNotification('payfip'));
        self::assertInstanceOf(StripeCheckoutAdapter::class, $configures->pourNotification('stripe'));

        $eteints = new SelecteurPaiementEnLigne([
            $payFipReel(null),
            $stripe(null),
            new PayFipBoutiqueAdapter(new PayFipStubAdapter(), $signer),
            new PspCbStubAdapter($signer),
        ]);
        self::assertInstanceOf(PayFipBoutiqueAdapter::class, $eteints->pour($regie));
        self::assertInstanceOf(PspCbStubAdapter::class, $eteints->pour($dsp));
        self::assertNull($eteints->pourNotification('payfip'), 'un prestataire éteint ne reçoit pas de notification');
        self::assertNull($eteints->pourNotification('inconnu'));
    }
}
