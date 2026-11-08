<?php

declare(strict_types=1);

namespace App\Vente\Nf525\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un morceau du fichier ZIP d'une archive fiscale annuelle (#342, #343).
 *
 * **Une table à part, et c'est la séparation que demande #343.** L'archive ne vit ni dans les tables
 * de vente ni dans celles du contrôle d'accès : les écrans de vente ne la lisent jamais, et lister les
 * archives (`FiscalArchive`) ne charge pas leurs fichiers.
 *
 * **En morceaux de 4 Mio, parce que la base refuse au-delà de 16.** `max_allowed_packet` vaut 16 Mio
 * sur MariaDB (mesuré en préprod le 05/11/2026) ; l'archive d'une année de piscine les dépasse sans
 * peine. Un seul BLOB aurait marché sur tous les jeux d'essai et échoué chez le premier vrai client,
 * le 1er janvier.
 *
 * **Pas dans la GED.** Un document de la GED se supprime avec les droits de la GED, et sa purge physique
 * suit trente jours plus tard. Une archive fiscale qui se conserve six ans (BOI-TVA-DECLA-30-10-30
 * § 250) ne doit dépendre d'aucune politique de rétention qu'un autre module règle. Ici,
 * `InalterabiliteListener` refuse modification et suppression.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nf525_fiscal_archive_file')]
#[ORM\UniqueConstraint(name: 'uniq_fiscal_archive_file_part', columns: ['archive_id', 'part'])]
class FiscalArchiveFile
{
    public const PART_SIZE = 4 * 1024 * 1024;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: FiscalArchive::class, inversedBy: 'parts')]
    #[ORM\JoinColumn(name: 'archive_id', nullable: false)]
    private FiscalArchive $archive;

    /** Rang du morceau, à partir de 0. */
    #[ORM\Column]
    private int $part;

    /** @var resource|string */
    #[ORM\Column(type: 'blob')]
    private mixed $content;

    public function __construct(FiscalArchive $archive, int $part, string $content)
    {
        $this->id = Uuid::v4();
        $this->archive = $archive;
        $this->part = $part;
        $this->content = $content;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArchive(): FiscalArchive
    {
        return $this->archive;
    }

    public function getPart(): int
    {
        return $this->part;
    }

    /** Le contenu binaire, qu'il revienne de la base en flux ou qu'il vienne d'être construit. */
    public function getContent(): string
    {
        if (\is_resource($this->content)) {
            rewind($this->content);
            $lu = stream_get_contents($this->content);

            return $lu === false ? '' : $lu;
        }

        return (string) $this->content;
    }
}
