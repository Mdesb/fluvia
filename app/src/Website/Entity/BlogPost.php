<?php

declare(strict_types=1);

namespace App\Website\Entity;

use App\Website\Enum\PublicationStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un article du blog de l'éditeur (ED-10).
 *
 * ⚠ **CETTE ENTITÉ N'EST RATTACHÉE À AUCUN ÉTABLISSEMENT, ET C'EST DÉLIBÉRÉ.** Le blog appartient au
 * site de **l'éditeur** — celui qui vend la plateforme — pas à un client. Lui donner un
 * `etablissement` ferait croire qu'il se cloisonne, et la question « quel établissement lit cet
 * article ? » n'a pas de réponse : le lecteur est un inconnu qui n'a pas de compte.
 *
 * Ce qui remplace le cloisonnement, c'est l'endroit d'où l'on écrit : toutes les opérations
 * d'administration passent par `/editor/website/**`, gardées par
 * {@see \App\Subscription\Security\EditorOnly}. L'entité n'est **jamais exposée directement** par API
 * Platform — même patron que `Subscription`, où `EditorPlan` est une ressource et `Plan` une entité.
 *
 * **La visibilité publique tient en une phrase, et elle vit dans {@see \App\Website\Service\BlogReader} :**
 * `status = published` ET `publishedAt <= maintenant`. Deux conditions, jamais une. Ne garder que le
 * statut publierait immédiatement tout article planifié ; ne garder que la date publierait les
 * brouillons datés.
 */
#[ORM\Entity]
#[ORM\Table(name: 'website_blog_post')]
#[ORM\UniqueConstraint(name: 'uniq_website_blog_post_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_website_blog_post_publication', columns: ['status', 'published_at'])]
class BlogPost
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * L'adresse publique de l'article — `\/blog\/{slug}`.
     *
     * ⚠ **Elle ne change plus une fois l'article publié**, et le refus est posé dans
     * {@see \App\Website\Service\BlogEditor}. Un lien publié est une promesse tenue par quelqu'un
     * d'autre : un partage, un signet, un résultat de recherche, un lien entrant. Le renommer ne
     * casse rien chez nous — c'est chez les autres que la page disparaît, et personne ici ne le voit.
     */
    #[ORM\Column(length: 160)]
    private string $slug = '';

    #[ORM\Column(length: 200)]
    private string $title = '';

    /**
     * Le chapô : ce qu'on lit dans la liste, et ce qui part en description pour les moteurs.
     *
     * Obligatoire, alors qu'on aurait pu le déduire des premiers mots du corps. Une description
     * découpée au milieu d'une phrase est ce qu'un moteur affichera sous le titre pendant des mois.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $excerpt = '';

    /**
     * Le corps, en HTML **déjà assaini** ({@see \App\Website\Service\BodySanitizer}).
     *
     * ⚠ Assaini à l'ÉCRITURE, jamais au rendu. Assainir au rendu obligerait chaque gabarit, chaque
     * flux RSS et chaque futur export à se souvenir de le faire ; celui qui oublie sert du HTML brut.
     * Ici la base ne contient que ce qui est déjà sûr, et le rendu peut faire confiance à la colonne.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    /**
     * L'image d'en-tête, par son adresse.
     *
     * **Une adresse, pas un envoi de fichier.** Il n'y a pas d'espace de stockage pour les visuels du
     * site de l'éditeur, et en inventer un pour ce lot serait un module de plus. Un champ d'adresse
     * est honnête : il dit ce qu'il fait, et le jour où l'envoi existe, il recevra l'adresse produite.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $coverUrl = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $coverAlt = null;

    #[ORM\Column(length: 16, enumType: PublicationStatus::class, options: ['default' => 'draft'])]
    private PublicationStatus $status = PublicationStatus::Draft;

    /**
     * La date à partir de laquelle l'article est public.
     *
     * Dans le futur, c'est une planification — sans tâche planifiée pour la déclencher, donc sans
     * rien qui puisse ne pas tourner ce jour-là.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne(targetEntity: BlogCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BlogCategory $category = null;

    /**
     * La signature affichée.
     *
     * Un texte, et non un lien vers `Utilisateur` : une signature est un choix éditorial — on signe
     * du nom de la maison, d'un prénom, ou de personne. Lier au compte qui a cliqué « publier »
     * ferait signer l'article par l'administrateur qui corrige une virgule six mois plus tard.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $authorName = null;

    /** Ce que les moteurs affichent sous le titre. À défaut, le chapô. */
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Cet article est-il servi au public à cet instant ?
     *
     * ⚠ La règle est ici, et {@see \App\Website\Service\BlogReader} la répète en SQL parce qu'on ne
     * charge pas mille articles pour en filtrer trois. Les deux doivent dire la même chose — un test
     * les confronte sur les quatre cas (brouillon daté, publié sans date, publié daté au futur,
     * publié daté au passé), justement parce que deux expressions de la même règle divergent un jour.
     */
    public function isVisible(\DateTimeImmutable $instant): bool
    {
        return PublicationStatus::Published === $this->status
            && null !== $this->publishedAt
            && $this->publishedAt <= $instant;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getExcerpt(): string
    {
        return $this->excerpt;
    }

    public function setExcerpt(string $excerpt): self
    {
        $this->excerpt = $excerpt;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getCoverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function setCoverUrl(?string $coverUrl): self
    {
        $this->coverUrl = $coverUrl;

        return $this;
    }

    public function getCoverAlt(): ?string
    {
        return $this->coverAlt;
    }

    public function setCoverAlt(?string $coverAlt): self
    {
        $this->coverAlt = $coverAlt;

        return $this;
    }

    public function getStatus(): PublicationStatus
    {
        return $this->status;
    }

    public function setStatus(PublicationStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getCategory(): ?BlogCategory
    {
        return $this->category;
    }

    public function setCategory(?BlogCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): self
    {
        $this->authorName = $authorName;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): self
    {
        $this->metaDescription = $metaDescription;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $instant): self
    {
        $this->updatedAt = $instant;

        return $this;
    }
}
