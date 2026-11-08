<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Vente\Nf525\Entity\FiscalArchive;
use App\Vente\Nf525\Entity\FiscalArchiveFile;
use PHPUnit\Framework\TestCase;

/**
 * #342 — l'archive est rangée en morceaux de 4 Mio, parce que MariaDB refuse un paquet de plus de
 * 16 Mio (`max_allowed_packet`, mesuré en préprod le 05/11/2026). Les jeux d'essai produisent des
 * archives de quelques kilo-octets : sans ce test, le découpage ne serait jamais exercé avant le
 * premier vrai client.
 */
final class FiscalArchivePartsTest extends TestCase
{
    public function testUnFichierPlusGrosQuUnMorceauSeDecoupeEtSeRecomposeALIdentique(): void
    {
        $zip = random_bytes(2 * FiscalArchiveFile::PART_SIZE + 17);
        $archive = (new FiscalArchive())->attachFile('a.zip', $zip, 'sig');

        self::assertSame(3, $archive->getPartCount());
        self::assertSame($zip, $archive->getContent(), 'Recomposé octet pour octet, dans l\'ordre.');
        self::assertSame(hash('sha256', $zip), $archive->getSha256());
        self::assertSame(\strlen($zip), $archive->getFileSize());
    }

    public function testLeFichierDUneArchiveNeSeRemplacePas(): void
    {
        $archive = (new FiscalArchive())->attachFile('a.zip', 'contenu', 'sig');

        $this->expectException(\LogicException::class);
        $archive->attachFile('b.zip', 'autre', 'sig');
    }
}
