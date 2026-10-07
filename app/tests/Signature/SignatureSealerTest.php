<?php

declare(strict_types=1);

namespace App\Tests\Signature;

use App\Signature\Doctrine\SignatureImmutabilityListener;
use App\Signature\Entity\ElectronicSignature;
use App\Signature\Enum\SignedDocumentType;
use App\Signature\Service\ImmutableSignatureException;
use App\Signature\Service\SignatureSealer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LE SOCLE DE SIGNATURE PROUVE CE QU'IL PROMET : le sceau lie le hash à la clé, la chaîne se lie au
 * maillon précédent, et ALTÉRER LE DOCUMENT SIGNÉ CASSE LE SCEAU. Sans ce dernier point, une
 * « signature » ne serait qu'une image décorative posée à côté d'un document qu'on pourrait réécrire.
 */
final class SignatureSealerTest extends TestCase
{
    private const KEY = 'cle-de-test-scellement-signature-1234567890';
    private const OTHER_KEY = 'une-autre-cle-totalement-differente-987654';
    private const TARGET = '018f0000-0000-7000-8000-000000000001';

    /** Sealer dont on contrôle le maillon précédent, sans base : `lastLink` est surchargé. */
    private function sealer(?ElectronicSignature $previous, string $key = self::KEY): SignatureSealer
    {
        $em = $this->createStub(EntityManagerInterface::class);

        return new class($em, $key, $previous) extends SignatureSealer {
            public function __construct(EntityManagerInterface $em, string $key, private readonly ?ElectronicSignature $previous)
            {
                parent::__construct($em, $key);
            }

            public function lastLink(ElectronicSignature $signature): ?ElectronicSignature
            {
                return $this->previous;
            }
        };
    }

    private function signature(string $documentHash): ElectronicSignature
    {
        // targetId et signedAt FIXES : sans eux, deux signatures « identiques » différeraient par leur
        // uuid aléatoire et leur instant, et le test de reproductibilité ne mesurerait rien.
        return (new ElectronicSignature())
            ->setDocumentType(SignedDocumentType::SepaMandate)
            ->setTargetType('SepaMandate')
            ->setTargetId(Uuid::fromString(self::TARGET))
            ->setDocumentHash($documentHash)
            ->setSignerName('Jean Testeur')
            ->setSignedAt(new \DateTimeImmutable('2026-09-09 10:00:00'));
    }

    public function testTheSealIsAnHmacOfTheHashUnderTheKey(): void
    {
        $sig = $this->sealer(null)->seal($this->signature('hash-du-mandat-A'));

        self::assertSame(1, $sig->getSequenceNumber(), 'premier maillon');
        self::assertNull($sig->getPreviousHash(), 'aucun précédent');
        self::assertNotSame('', $sig->getHash(), 'un hash est posé');

        // Le seal EST le HMAC du hash sous la clé : vérifié sans réimplémenter le calcul du hash (on
        // part de celui qui a été posé).
        self::assertSame(
            hash_hmac('sha256', $sig->getHash(), self::KEY),
            $sig->getSeal(),
            'le seal est HMAC-SHA256(hash, clé)',
        );
    }

    public function testAnotherKeyProducesAnotherSeal(): void
    {
        // ⚠ LE TÉMOIN QUI PROUVE QUE LA CLÉ COMPTE. Un seal indépendant de la clé se reproduirait par
        // n'importe qui : l'opposabilité tiendrait à un secret qui n'en est pas un.
        $a = $this->sealer(null, self::KEY)->seal($this->signature('meme-document'));
        $b = $this->sealer(null, self::OTHER_KEY)->seal($this->signature('meme-document'));

        self::assertSame($a->getHash(), $b->getHash(), 'même document, même hash');
        self::assertNotSame($a->getSeal(), $b->getSeal(), 'clé différente, seal différent');
    }

    public function testTheChainLinksToThePreviousLink(): void
    {
        $first = $this->sealer(null)->seal($this->signature('hash-1'));
        $second = $this->sealer($first)->seal($this->signature('hash-2'));

        self::assertSame(2, $second->getSequenceNumber(), 'la séquence suit');
        self::assertSame($first->getHash(), $second->getPreviousHash(), 'chaînée au précédent');
        self::assertNotSame($first->getHash(), $second->getHash(), 'un maillon distinct');
    }

    public function testAlteringTheSignedDocumentBreaksTheSeal(): void
    {
        // LE FILET. Même signataire, même instant, même cible — SEUL le document change. Si le hash ne
        // bougeait pas, on pourrait réécrire le mandat après signature sans que la preuve le dise.
        $before = $this->sealer(null)->seal($this->signature('le-mandat-original'));
        $after = $this->sealer(null)->seal($this->signature('le-mandat-falsifie'));

        self::assertNotSame($before->getHash(), $after->getHash(), 'changer le document change le hash');
        self::assertNotSame($before->getSeal(), $after->getSeal(), '…donc casse le sceau');
    }

    public function testTwoIdenticalSignaturesGiveTheSameSeal(): void
    {
        // Le pendant du filet : la reproductibilité. Une vérification ultérieure qui rejoue le même
        // faisceau doit retomber sur le même seal, sinon aucune altération ne serait distinguable d'un
        // simple recalcul instable.
        $one = $this->sealer(null)->seal($this->signature('identique'));
        $two = $this->sealer(null)->seal($this->signature('identique'));

        self::assertSame($one->getHash(), $two->getHash());
        self::assertSame($one->getSeal(), $two->getSeal());
    }

    public function testASealedSignatureIsImmutable(): void
    {
        // `PreRemoveEventArgs` est `final` : on construit de VRAIS args d'événement avec un EM stubé,
        // plutôt que de les doubler.
        $listener = new SignatureImmutabilityListener();
        $sig = $this->signature('x');
        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];

        $updateBlocked = false;
        try {
            $listener->preUpdate(new PreUpdateEventArgs($sig, $em, $changeSet));
        } catch (ImmutableSignatureException) {
            $updateBlocked = true;
        }
        self::assertTrue($updateBlocked, 'une signature scellée ne doit pas être modifiable');

        $removeBlocked = false;
        try {
            $listener->preRemove(new PreRemoveEventArgs($sig, $em));
        } catch (ImmutableSignatureException) {
            $removeBlocked = true;
        }
        self::assertTrue($removeBlocked, 'une signature scellée ne doit pas être supprimable');
    }

    public function testTheGuardOnlyAppliesToSignatures(): void
    {
        // ⚠ TÉMOIN NÉGATIF. Une garde qui lèverait sur TOUT objet figerait l'ORM entier et ne se
        // remarquerait qu'au premier update d'autre chose. On prouve qu'elle ÉPARGNE le reste.
        $listener = new SignatureImmutabilityListener();
        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];

        $listener->preUpdate(new PreUpdateEventArgs(new \stdClass(), $em, $changeSet));
        $this->expectNotToPerformAssertions();
    }
}
