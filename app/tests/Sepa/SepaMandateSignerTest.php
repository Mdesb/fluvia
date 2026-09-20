<?php

declare(strict_types=1);

namespace App\Tests\Sepa;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Service\SepaMandateComposer;
use App\Sepa\Service\SepaMandateSigner;
use App\Signature\Entity\ElectronicSignature;
use App\Signature\Enum\SignedDocumentType;
use App\Signature\Service\SignatureSealer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * LE MANDAT SEPA EST VRAIMENT SIGNÉ, ET SA PREUVE TIENT SUR LE DOCUMENT. On prouve que le document
 * reprend les données du mandat, que la signature le scelle par son hash, et — le point propre au
 * mandat — que le document se REPRODUIT depuis le mandat (donc la preuve se revérifie sans stocker
 * le texte).
 */
final class SepaMandateSignerTest extends TestCase
{
    private const KEY = 'cle-test-mandat-sepa-1234567890ab';

    private function mandate(): MandatSepa
    {
        return (new MandatSepa())
            ->setRum('FR76-RUM-0001')
            ->setDebiteurNom('Marie Dupont')
            ->setIban4Derniers('6789')
            ->setBicDebiteur('BNPAFRPPXXX')
            ->setEtablissement(new Etablissement());
    }

    private function signer(): SepaMandateSigner
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $sealer = new class($em, self::KEY) extends SignatureSealer {
            public function lastLink(ElectronicSignature $signature): ?ElectronicSignature
            {
                return null;
            }
        };

        return new SepaMandateSigner($em, new SepaMandateComposer(), $sealer);
    }

    public function testTheComposedMandateStatesItsData(): void
    {
        $doc = (new SepaMandateComposer())->compose($this->mandate());

        self::assertStringContainsString('FR76-RUM-0001', $doc, 'la RUM');
        self::assertStringContainsString('Marie Dupont', $doc, 'le débiteur');
        self::assertStringContainsString('6789', $doc, 'les 4 derniers de l\'IBAN');
        self::assertStringContainsString('BNPAFRPPXXX', $doc, 'le BIC');
        self::assertStringNotContainsString('FR76-RUM-0001-COMPLET', $doc, 'jamais l\'IBAN complet');
    }

    public function testSigningProducesASealedSignatureBoundToTheMandate(): void
    {
        $mandate = $this->mandate();
        $signer = (new Client())->setPrenom('Marie')->setNom('Dupont');
        $signature = $this->signer()->sign($mandate, $signer, null, 'faux-png', '10.0.0.1', 'UA');

        self::assertSame(SignedDocumentType::SepaMandate, $signature->getDocumentType());
        self::assertSame('SepaMandate', $signature->getTargetType());
        self::assertSame((string) $mandate->getId(), (string) $signature->getTargetId(), 'la signature vise CE mandat');
        self::assertSame('Marie Dupont', $signature->getSignerName(), 'le titulaire déclaré au mandat');
        // Scellé : le seal est le HMAC du hash sous la clé.
        self::assertSame(hash_hmac('sha256', $signature->getHash(), self::KEY), $signature->getSeal());
    }

    public function testTheProofReverifiesAgainstAFreshCompose(): void
    {
        // PROPRE AU MANDAT : le texte n'est pas stocké. On prouve qu'il se REPRODUIT depuis le mandat
        // (données stables), donc que le hash signé se revérifie sans avoir gardé le texte. Si la
        // composition n'était pas déterministe, on ne pourrait jamais opposer le mandat signé.
        $mandate = $this->mandate();
        $signature = $this->signer()->sign($mandate, null, null, null);

        $recomposed = (new SepaMandateComposer())->compose($mandate);
        self::assertSame(
            hash('sha256', $recomposed),
            $signature->getDocumentHash(),
            'le document se reproduit depuis le mandat, et le hash signé colle',
        );
    }
}
