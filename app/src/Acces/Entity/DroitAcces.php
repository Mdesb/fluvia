<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Projection locale d'un droit vendu M1/M2 (point ouvert n°9 du plan) : cache la fenêtre de validité,
 * le crédit restant et les marges par défaut pour que le contrôleur valide en < 1 s (US-L3-03), y
 * compris hors-ligne. La source de vérité reste M1/M2 ; L3 consomme et décompte, ne crée pas le
 * droit. Alimentée par `ProjectionDroitInterface` (stub en L3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_droit_acces')]
#[ORM\Index(columns: ['updated_at'], name: 'idx_droit_acces_updated_at')]
#[ApiResource(
    shortName: 'DroitAcces',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
    ],
    normalizationContext: ['groups' => ['droit:read']],
)]
class DroitAcces
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['droit:read', 'appairage:read', 'passage:read'])]
    private Uuid $id;

    #[ORM\Column(length: 24, enumType: TypeDroitAcces::class)]
    #[Groups(['droit:read', 'passage:read'])]
    private TypeDroitAcces $sourceType = TypeDroitAcces::Billet;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['droit:read'])]
    private ?Uuid $billetSupportRef = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['droit:read'])]
    private ?Uuid $produitRef = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['droit:read'])]
    private ?Uuid $reservationRef = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['droit:read'])]
    private ?\DateTimeImmutable $fenetreDebut = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['droit:read'])]
    private ?\DateTimeImmutable $fenetreFin = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['droit:read'])]
    private ?int $creditRestant = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['droit:read'])]
    private ?int $margeAvanceDefaut = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['droit:read'])]
    private ?int $margeRetardDefaut = null;

    #[ORM\ManyToOne(targetEntity: SousReseau::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['droit:read'])]
    private ?SousReseau $sousReseau = null;

    /**
     * ⚠ EXCEPTION TRANSITOIRE À D87 — CE QUI L'ÉTEINT EST UNE CONDITION, PAS UNE DATE (D90).
     *
     * Ces deux types de source n'ont, à ce jour, **aucun moyen de déclarer les zones qu'ils
     * ouvrent** : un badge de personnel n'a pas de produit dont hériter, et un droit né d'une
     * réservation non plus. Les soumettre à la règle stricte ne les restreindrait pas, ça les
     * fermerait — le personnel resterait dehors.
     *
     * **Ce qui fait disparaître cette constante :** la livraison de D88 (les zones d'un badge
     * viennent de la FONCTION) et de D89 (celles d'une réservation viennent de l'ACTIVITÉ). Le jour
     * où l'une des deux existe, retirer le type correspondant d'ici est un geste, et son absence se
     * voit.
     *
     * ⚠ Et l'exception ne doit pas survivre en silence : `DroitAccesExceptionTransitoireTest`
     * ÉCHOUE le jour où un droit d'un type exempté porte déjà des zones déclarées — parce que cela
     * prouve que le mécanisme existe, donc que l'exception n'a plus d'objet. Sans ce contrôle, un
     * transitoire devient un permanent que personne ne remesure ; c'est exactement le sort qu'a
     * connu le paragraphe de `ValidationPassageHandler` qu'on vient de retirer, dont l'argument
     * était mort bien avant qu'on s'en aperçoive.
     *
     * @var list<TypeDroitAcces>
     */
    public const TYPES_EXEMPTES_DE_ZONE = [
        TypeDroitAcces::Personnel,
        TypeDroitAcces::Booking,
    ];

    /**
     * Les espaces que ce droit ouvre. VIDE = il n'en ouvre AUCUN (D87, 30/08/2026) — sauf pour les
     * types listés dans `TYPES_EXEMPTES_DE_ZONE` ci-dessus, et c'est transitoire.
     *
     * ⚠ CETTE RÈGLE A ÉTÉ L'INVERSE, ET SAVOIR POURQUOI ÉVITE DE LA RETOURNER À NOUVEAU. Le vide
     * ouvrait tout, par compatibilité : les droits déjà projetés n'en portaient aucun, et les
     * refuser d'un coup aurait fermé des portes devant des gens qui avaient payé.
     *
     * Cet argument est mort le jour où on l'a mesuré : quatre droits en base, trois sans espace,
     * tous des données de test — personne devant la porte. Maxime a tranché en le rappelant :
     * la commercialisation n'a pas commencé. Le changement était gratuit ce jour-là et coûteux dès
     * le premier client, d'où l'urgence de le faire pendant que la fenêtre existait.
     *
     * Le sens de l'erreur est désormais celui qui restreint : une porte fermée à tort se rouvre en
     * déclarant une zone ; une porte ouverte à tort a déjà laissé passer quelqu'un.
     *
     * Recopiés à la PROJECTION et non lus depuis le produit : c'est ce droit-ci que les terminaux
     * embarquent pour décider hors ligne. Une règle qui ne vivrait que côté produit serait
     * inapplicable par un lecteur déconnecté — c'est-à-dire précisément quand elle compte.
     *
     * @var Collection<int, EspaceAcces>
     */
    #[ORM\ManyToMany(targetEntity: EspaceAcces::class)]
    #[ORM\JoinTable(name: 'acces_droit_espace_autorise')]
    #[Groups(['droit:read'])]
    private Collection $authorisedSpaces;

    #[ORM\Column(length: 12, enumType: StatutProjectionDroit::class, options: ['default' => 'valide'])]
    #[Groups(['droit:read'])]
    private StatutProjectionDroit $statutProjection = StatutProjectionDroit::Valide;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['droit:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['droit:read'])]
    private ?\DateTimeImmutable $synchroniseLe = null;

    /**
     * Reference de cette carte dans le logiciel d'ou elle a ete reprise (T2, SPEC §4).
     *
     * ⚠ **Exigence propre aux credits de cartes** : un credit restant est une dette envers le
     * client, et une contestation doit pouvoir remonter au fichier d'origine. Sans cette reference,
     * « il me restait six entrees » se discute de memoire, six semaines apres, au guichet.
     *
     * `null` pour toute carte emise dans l'application : elle ne vaut que pour une reprise.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $externalRef = null;

    /** Le lot de reprise qui a cree ce droit — un `?Uuid` nu, jamais une relation Doctrine (D2). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $importBatchRef = null;

    /**
     * Dernière modification de ce que ce droit fait décider à une porte (statut, fenêtre, crédit,
     * zones, sous-réseau, type, marges), en UTC.
     *
     * Tenue par `AccessProjectionVersionListener` pour les écritures ORM, et par l'`UPDATE` lui-même
     * pour les chemins en SQL direct (crédit, échéance). Elle n'avance NI sur une lecture, NI sur un
     * rejeu à l'identique, NI sur `synchroniseLe` : c'est ce qui la rend utilisable comme
     * `updatedSince` par l'API partenaire (décision de Maxime du 04/10, « date fiable sur les droits »).
     * Sur les droits antérieurs à la colonne, elle vaut l'heure de la migration : un partenaire les
     * voit tous une fois, ce qui est le sens sûr de l'erreur.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getExternalRef(): ?string
    {
        return $this->externalRef;
    }

    public function setExternalRef(?string $externalRef): self
    {
        $this->externalRef = $externalRef;

        return $this;
    }

    public function getImportBatchRef(): ?Uuid
    {
        return $this->importBatchRef;
    }

    public function setImportBatchRef(?Uuid $importBatchRef): self
    {
        $this->importBatchRef = $importBatchRef;

        return $this;
    }


    public function __construct()
    {
        $this->authorisedSpaces = new ArrayCollection();
        $this->id = Uuid::v4();
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSourceType(): TypeDroitAcces
    {
        return $this->sourceType;
    }

    public function setSourceType(TypeDroitAcces $sourceType): self
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getBilletSupportRef(): ?Uuid
    {
        return $this->billetSupportRef;
    }

    public function setBilletSupportRef(?Uuid $billetSupportRef): self
    {
        $this->billetSupportRef = $billetSupportRef;

        return $this;
    }

    public function getProduitRef(): ?Uuid
    {
        return $this->produitRef;
    }

    public function setProduitRef(?Uuid $produitRef): self
    {
        $this->produitRef = $produitRef;

        return $this;
    }

    public function getReservationRef(): ?Uuid
    {
        return $this->reservationRef;
    }

    public function setReservationRef(?Uuid $reservationRef): self
    {
        $this->reservationRef = $reservationRef;

        return $this;
    }

    public function getFenetreDebut(): ?\DateTimeImmutable
    {
        return $this->fenetreDebut;
    }

    public function setFenetreDebut(?\DateTimeImmutable $fenetreDebut): self
    {
        $this->fenetreDebut = $fenetreDebut;

        return $this;
    }

    public function getFenetreFin(): ?\DateTimeImmutable
    {
        return $this->fenetreFin;
    }

    public function setFenetreFin(?\DateTimeImmutable $fenetreFin): self
    {
        $this->fenetreFin = $fenetreFin;

        return $this;
    }

    public function getCreditRestant(): ?int
    {
        return $this->creditRestant;
    }

    public function setCreditRestant(?int $creditRestant): self
    {
        $this->creditRestant = $creditRestant;

        return $this;
    }

    public function getMargeAvanceDefaut(): ?int
    {
        return $this->margeAvanceDefaut;
    }

    public function setMargeAvanceDefaut(?int $margeAvanceDefaut): self
    {
        $this->margeAvanceDefaut = $margeAvanceDefaut;

        return $this;
    }

    public function getMargeRetardDefaut(): ?int
    {
        return $this->margeRetardDefaut;
    }

    public function setMargeRetardDefaut(?int $margeRetardDefaut): self
    {
        $this->margeRetardDefaut = $margeRetardDefaut;

        return $this;
    }

    public function getSousReseau(): ?SousReseau
    {
        return $this->sousReseau;
    }

    public function setSousReseau(?SousReseau $sousReseau): self
    {
        $this->sousReseau = $sousReseau;

        return $this;
    }

    public function getStatutProjection(): StatutProjectionDroit
    {
        return $this->statutProjection;
    }

    public function setStatutProjection(StatutProjectionDroit $statutProjection): self
    {
        $this->statutProjection = $statutProjection;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getSynchroniseLe(): ?\DateTimeImmutable
    {
        return $this->synchroniseLe;
    }

    public function setSynchroniseLe(?\DateTimeImmutable $synchroniseLe): self
    {
        $this->synchroniseLe = $synchroniseLe;

        return $this;
    }

    /** @return Collection<int, EspaceAcces> */
    public function getAuthorisedSpaces(): Collection
    {
        return $this->authorisedSpaces;
    }

    public function addAuthorisedSpace(EspaceAcces $space): self
    {
        if (!$this->authorisedSpaces->contains($space)) {
            $this->authorisedSpaces->add($space);
        }

        return $this;
    }

    /**
     * ⚠ POSE AVANT D'EN AVOIR BESOIN, ET C'EST DELIBERE.
     *
     * Le serialiseur de Symfony n'accepte une collection en ecriture que si l'AJOUT ET LE RETRAIT
     * existent. Sans les deux, il ignore la propriete -- sans erreur. `SousReseau` en est mort la
     * meme nuit : `PATCH { espaces: [...] }` repondait 200 en n'enregistrant rien.
     *
     * Deux lignes maintenant valent une soiree plus tard.
     */
    public function removeAuthorisedSpace(EspaceAcces $space): self
    {
        $this->authorisedSpaces->removeElement($space);

        return $this;
    }

    /**
     * Ce droit ouvre-t-il cet espace ?
     *
     * Vide = aucune porte (D87), sauf pour les types exemptés : voir le docbloc de la propriété.
     * La comparaison porte sur la représentation textuelle de l'identifiant — `getId()` rend des objets `Uuid`, qu'une
     * comparaison stricte d'objets distinguerait à tort (D58).
     */
    public function ouvre(EspaceAcces $space): bool
    {
        // VIDE = AUCUNE PORTE (D87). Voir le docbloc de `$authorisedSpaces` pour l'histoire de ce
        // `false`, qui a été un `true` jusqu'au 30/08/2026.
        //
        // ⚠ Ceci ne dit rien de la VALIDITÉ du billet, seulement des portes qu'il ouvre (D86). Un
        // billet vendu est toujours connu du contrôle d'accès : un agent peut le contrôler à la
        // main là où il n'y a pas de matériel, et cette méthode n'est pas sur ce chemin-là.
        if ($this->authorisedSpaces->isEmpty()) {
            return in_array($this->sourceType, self::TYPES_EXEMPTES_DE_ZONE, true);
        }

        foreach ($this->authorisedSpaces as $autorise) {
            if ((string) $autorise->getId() === (string) $space->getId()) {
                return true;
            }
        }

        return false;
    }
}
