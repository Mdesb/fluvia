<?php

declare(strict_types=1);

namespace App\Website\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une rubrique du blog (ED-10).
 *
 * **Une seule rubrique par article, pas des étiquettes.** Des étiquettes multiples paraissent plus
 * riches et produisent, en pratique, autant de pages de listes presque vides — chacune indexable,
 * chacune en concurrence avec les autres dans les résultats de recherche. Une rubrique par article
 * donne des listes qui valent la peine d'être lues, et une arborescence qu'un moteur comprend.
 *
 * ⚠ Supprimer une rubrique **ne supprime pas ses articles** : la colonne repasse à `NULL`
 * (`onDelete: SET NULL`). L'inverse ferait disparaître des pages publiées d'un clic dans un écran
 * d'administration, sans que rien ne prévienne que douze articles partaient avec.
 */
#[ORM\Entity]
#[ORM\Table(name: 'website_blog_category')]
#[ORM\UniqueConstraint(name: 'uniq_website_blog_category_slug', columns: ['slug'])]
class BlogCategory
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $slug = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }
}
