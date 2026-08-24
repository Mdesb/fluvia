<?php

declare(strict_types=1);

namespace App\Tests\Social\Integration;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Social\Crypto\SocialTokenCipher;
use App\Social\Entity\SocialAccount;
use App\Social\Enum\SocialNetwork;
use App\Social\Service\SocialTokenKeyRotator;
use App\Tests\Social\SocialApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rotation de clé sur de vraies lignes (SOC-1, mécanisme de rotation).
 *
 * Les comptes des fixtures sont chiffrés avec la clé de l'environnement, qu'on ne connaît pas ici :
 * leurs jetons sont donc vidés au départ, pour que les compteurs portent exactement sur les comptes
 * que ce test crée. Les mêler donnerait des décomptes qu'on interpréterait de travers.
 */
final class SocialTokenKeyRotatorTest extends SocialApiTestCase
{
    private const V1 = 'Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmc=';
    private const V2 = 'YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=';

    public function testLaRotationRechiffreEtResteReprenable(): void
    {
        $this->viderLesJetonsDesFixtures();
        $this->creerComptes(3, (new SocialTokenCipher(self::V1)));

        $rotator = $this->rotator();
        self::assertSame(3, $rotator->remaining());

        $premier = $rotator->rotate(2);
        self::assertSame(2, $premier['rotated']);
        self::assertSame(0, $premier['failed']);
        self::assertSame(1, $premier['remaining'], 'La borne du passage est respectee.');

        $second = $rotator->rotate(10);
        self::assertSame(1, $second['rotated']);
        self::assertSame(0, $second['remaining']);

        // Idempotence : relancer ne retouche rien. C'est ce qui permet de relancer sans réfléchir.
        $troisieme = $rotator->rotate(10);
        self::assertSame(0, $troisieme['rotated']);
        self::assertSame(0, $troisieme['remaining']);
    }

    public function testLesJetonsRestentDechiffrablesApresRotation(): void
    {
        $this->viderLesJetonsDesFixtures();
        $this->creerComptes(1, new SocialTokenCipher(self::V1));

        $this->rotator()->rotate(10);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $compte = $em->getRepository(SocialAccount::class)->findOneBy(['remoteAccountId' => 'rotation-0']);
        self::assertNotNull($compte);

        $cipherV2 = $this->cipherV2();
        self::assertStringStartsWith('v2:', (string) $compte->getAccessTokenEncrypted());
        self::assertSame('jeton-acces-0', $cipherV2->decrypt((string) $compte->getAccessTokenEncrypted()));
        // Les deux jetons d'un même compte sont rechiffrés ensemble : traités séparément, une
        // interruption laisserait l'accès à la nouvelle version et le rafraîchissement à l'ancienne —
        // lisible tant que les deux clés sont là, illisible dès qu'on retire l'ancienne.
        self::assertSame('jeton-refresh-0', $cipherV2->decrypt((string) $compte->getRefreshTokenEncrypted()));
    }

    public function testLaSimulationNeToucheRien(): void
    {
        $this->viderLesJetonsDesFixtures();
        $this->creerComptes(2, new SocialTokenCipher(self::V1));

        $rapport = $this->rotator()->rotate(10, dryRun: true);

        self::assertSame(2, $rapport['rotated'], 'La simulation annonce ce qu elle aurait fait.');
        self::assertSame(2, $rapport['remaining'], 'Mais rien n a bouge en base.');
    }

    public function testLEtatCompteParVersionEtSignaleLIllisible(): void
    {
        $this->viderLesJetonsDesFixtures();
        $this->creerComptes(2, new SocialTokenCipher(self::V1));

        $etat = $this->rotator()->status();
        self::assertSame([1 => 4], $etat['byVersion'], '2 comptes x 2 jetons, tous en v1.');
        self::assertSame(0, $etat['unreadable']);

        // Une clé retirée trop tôt : les valeurs deviennent illisibles, et c'est ce qu'il faut voir
        // AVANT de la supprimer, pas apres.
        $sansAncienne = new SocialTokenKeyRotator($this->em(), new SocialTokenCipher(self::V2, null, 2));
        self::assertSame(4, $sansAncienne->status()['unreadable']);
    }

    private function rotator(): SocialTokenKeyRotator
    {
        return new SocialTokenKeyRotator($this->em(), $this->cipherV2());
    }

    private function cipherV2(): SocialTokenCipher
    {
        return new SocialTokenCipher(self::V2, '1:' . self::V1, 2);
    }

    private function creerComptes(int $nombre, SocialTokenCipher $avec): void
    {
        $em = $this->em();
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        for ($i = 0; $i < $nombre; ++$i) {
            $compte = new SocialAccount();
            $compte->setEstablishment($etabA)
                ->setNetwork(SocialNetwork::Mastodon)
                ->setHost('https://mastodon.social')
                ->setRemoteAccountId('rotation-' . $i)
                ->setHandle('@rotation' . $i . '@mastodon.social')
                ->setAccessTokenEncrypted($avec->encrypt('jeton-acces-' . $i))
                ->setRefreshTokenEncrypted($avec->encrypt('jeton-refresh-' . $i));
            $em->persist($compte);
        }
        $em->flush();
        $em->clear();
    }

    private function viderLesJetonsDesFixtures(): void
    {
        $em = $this->em();
        foreach ($em->getRepository(SocialAccount::class)->findAll() as $compte) {
            $compte->setAccessTokenEncrypted(null)->setRefreshTokenEncrypted(null);
        }
        $em->flush();
        $em->clear();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
