<?php

declare(strict_types=1);

namespace App\Website\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * La valeur d'un bloc de contenu du site vitrine (ED-10).
 *
 * ⚠ **LA CLÉ EST L'IDENTIFIANT, ET LA LISTE DES CLÉS VIT DANS LE CODE.** C'est le gabarit qui sait de
 * quels blocs la page est faite ; la base ne range que ce qu'on y a écrit. La liste de référence est
 * {@see \App\Website\Service\HomeBlocks} — et c'est ce qui permet à l'écran d'administration de
 * montrer un bloc **jamais rempli** au lieu de ne pas le montrer du tout.
 *
 * L'inverse — laisser la base décider des blocs existants — produit le défaut classique d'un petit
 * CMS : une clé disparaît d'une table, la page se rend sans elle, et personne ne voit qu'il manque un
 * titre. Ici, un bloc absent de la base est **visible dans l'écran** comme non rempli, et le rendu
 * public le saute sans inventer de texte de remplacement.
 *
 * `value` est du JSON parce que les quatre formes de bloc n'ont pas la même : une ligne, un
 * paragraphe, une liste de phrases, une liste de cartes. Le type attendu est déclaré à côté de la
 * clé, dans `HomeBlocks` — pas dans cette table, qui le recopierait sans jamais l'imposer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'website_content_block')]
class ContentBlock
{
    /**
     * Par exemple `home.hero.title`. Point-séparé : la page, la section, le rôle.
     *
     * ⚠ La COLONNE s'appelle `block_key`, pas `key` : `key` est un mot réservé de MySQL et de
     * MariaDB. Doctrine ne le devine pas — il faudrait l'entourer d'accents graves dans le mapping,
     * et un accent grave oublié quelque part produit une erreur de syntaxe SQL au moment le plus
     * inattendu. Un nom qui n'a jamais besoin d'être échappé n'a jamais le problème.
     */
    #[ORM\Id]
    #[ORM\Column(name: 'block_key', length: 80)]
    private string $key = '';

    /** @var array<int|string, mixed> */
    #[ORM\Column(name: 'block_value', type: 'json')]
    private array $value = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key = '')
    {
        $this->key = $key;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<int|string, mixed> */
    public function getValue(): array
    {
        return $this->value;
    }

    /** @param array<int|string, mixed> $value */
    public function setValue(array $value, \DateTimeImmutable $instant): self
    {
        $this->value = $value;
        $this->updatedAt = $instant;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
