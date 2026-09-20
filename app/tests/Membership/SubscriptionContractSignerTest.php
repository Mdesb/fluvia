<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Membership\Entity\Membership;
use App\Membership\Enum\MembershipPeriodicity;
use App\Membership\Service\SubscriptionContractComposer;
use App\Membership\Service\SubscriptionContractSigner;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Signature\Entity\ElectronicSignature;
use App\Signature\Enum\SignedDocumentType;
use App\Signature\Service\SignatureSealer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * LE CONTRAT SIGNÉ DIT CE QUI A ÉTÉ CONVENU, ET RESTE FIGÉ. On prouve que le texte reprend les termes
 * gelés, que la signature le scelle par son hash, et — le filet — qu'un changement de montant APRÈS
 * signature ne touche ni le texte signé ni sa preuve.
 */
final class SubscriptionContractSignerTest extends TestCase
{
    private const KEY = 'cle-test-contrat-123456789012345';

    private function subscription(int $cents = 3990): Membership
    {
        $payeur = (new Client())->setPrenom('Marie')->setNom('Dupont');
        $adherentClient = (new Client())->setPrenom('Léo')->setNom('Dupont');
        $adherent = (new Beneficiaire())->setClient($adherentClient);
        $mandat = (new MandatSepa())->setRum('FR76-RUM-001');

        return (new Membership())
            ->setPayeur($payeur)
            ->setAdherent($adherent)
            ->setMontantCentimes($cents)
            ->setPeriodicite(MembershipPeriodicity::Mensuel)
            ->setDateDebutEngagement(new \DateTimeImmutable('2026-09-09'))
            ->setDateFinEngagement(new \DateTimeImmutable('2027-09-08'))
            ->setPreavisResiliationJours(30)
            ->setMandatSepa($mandat)
            ->setEtablissement(new Etablissement());
    }

    private function signer(): SubscriptionContractSigner
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $sealer = new class($em, self::KEY) extends SignatureSealer {
            public function lastLink(ElectronicSignature $signature): ?ElectronicSignature
            {
                return null;
            }
        };

        return new SubscriptionContractSigner($em, new SubscriptionContractComposer(), $sealer);
    }

    public function testTheComposedDocumentStatesTheFrozenTerms(): void
    {
        $doc = (new SubscriptionContractComposer())->compose($this->subscription(3990));

        self::assertStringContainsString('Marie Dupont', $doc, 'le payeur');
        self::assertStringContainsString('Léo Dupont', $doc, 'l\'adhérent');
        self::assertStringContainsString('39,90 €', $doc, 'le montant');
        self::assertStringContainsString('mensuel', $doc, 'la périodicité');
        self::assertStringContainsString('FR76-RUM-001', $doc, 'le RUM du mandat');
        self::assertStringContainsString('09/09/2026', $doc, 'la date de début d\'engagement');
    }

    public function testSigningProducesASealedSignatureBoundToTheDocument(): void
    {
        $signer = (new Client())->setPrenom('Marie')->setNom('Dupont');
        $contract = $this->signer()->sign($this->subscription(), $signer, null, 'faux-png-base64', '10.0.0.1', 'UA-test');

        $sig = $contract->getSignature();
        self::assertNotNull($sig, 'le contrat porte une signature');
        self::assertSame(SignedDocumentType::SubscriptionContract, $sig->getDocumentType());
        self::assertSame('SubscriptionContract', $sig->getTargetType());
        self::assertSame((string) $contract->getId(), (string) $sig->getTargetId(), 'la signature vise CE contrat');
        // Le lien signature ↔ document : le hash de la signature EST le hash du texte gelé.
        self::assertSame(hash('sha256', $contract->getDocumentText()), $sig->getDocumentHash());
        // Scellé : le seal est le HMAC du hash sous la clé.
        self::assertSame(hash_hmac('sha256', $sig->getHash(), self::KEY), $sig->getSeal());
        self::assertSame('Marie Dupont', $sig->getSignerName(), 'le nom du signataire');
    }

    public function testTheSignedContractIsFrozenAgainstLaterTermChanges(): void
    {
        // LE FILET DU GEL. On signe, PUIS on change le montant courant de l'abonnement. Le contrat signé
        // ne doit pas bouger — ni son texte, ni sa preuve. Sinon « ce qui a été signé » deviendrait « ce
        // que dit l'abonnement aujourd'hui », et le client se verrait opposer un montant jamais accepté.
        $subscription = $this->subscription(3990);
        $contract = $this->signer()->sign($subscription, null, null, null);
        $textAtSigning = $contract->getDocumentText();

        $subscription->setMontantCentimes(9990); // le montant courant change APRÈS la signature

        self::assertSame($textAtSigning, $contract->getDocumentText(), 'le texte signé est gelé');
        self::assertStringContainsString('39,90 €', $contract->getDocumentText(), 'le montant signé…');
        self::assertStringNotContainsString('99,90 €', $contract->getDocumentText(), '…pas le nouveau');
        self::assertSame(
            hash('sha256', $textAtSigning),
            $contract->getSignature()?->getDocumentHash(),
            'la preuve tient sur le texte gelé',
        );
    }
}
