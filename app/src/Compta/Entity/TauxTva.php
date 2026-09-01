<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Compta\Enum\VatCategory;

/**
 * Taux de TVA (RG-TVA-06). Le taux réduit 2025 (point EXPERT #2) est créé `actif=false` par défaut ;
 * `MappingComptable` ne peut pointer que vers un taux actif.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_taux_tva')]
#[ApiResource(
    shortName: 'TauxTva',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'compta.gerer')"),
        new Patch(security: "is_granted('PERM', 'compta.gerer')"),
    ],
    normalizationContext: ['groups' => ['taux:read']],
    denormalizationContext: ['groups' => ['taux:write']],
)]
class TauxTva
{
    /** Libellé conventionnel du taux « hors champ » (0 %) seedé pour les opérations non commerciales. */
    public const LIBELLE_HORS_CHAMP = 'Hors champ (opération non commerciale)';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['taux:read', 'mapping:read', 'ligne:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['taux:read', 'taux:write'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['taux:read', 'taux:write', 'mapping:read', 'ligne:read'])]
    private string $taux = '0.00';

    /**
     * BT-151 — la categorie de TVA au sens d'EN 16931 (liste UNTDID 5305).
     *
     * ⚠ ELLE N'EST PAS LE TAUX, ET NE S'EN DEDUIT PAS QUAND IL VAUT ZERO. Mesure du 02/09 sur la
     * preproduction : six taux a 0 %, tous libelles « Hors champ (operation non commerciale) ».
     * Hors champ, c'est `O` — pas `Z` (taux zero), pas `E` (exonere). Les trois se ressemblent sur
     * une facture, se distinguent au controle fiscal, et n'appellent pas les memes mentions.
     *
     * ⚠ NULLABLE, ET C'EST LE POINT. Un defaut `S` aurait ete faux pour ces six taux — une migration
     * ne fabrique pas de donnee fiscale (D66-ter). Un taux sans categorie rend simplement ses
     * factures non emettables au format europeen, et `facturation:einvoicing:etat` le nomme. Le
     * manque reste visible au lieu d'etre rempli d'une supposition.
     *
     * La migration a pose `S` sur les taux POSITIFS seulement : la, il n'y a pas d'ambiguite, reduit
     * comme normal. C'est le taux qui distingue 5,5 % de 20 %, pas la categorie.
     */
    #[ORM\Column(length: 4, enumType: VatCategory::class, nullable: true)]
    #[Groups(['taux:read', 'taux:write'])]
    private ?VatCategory $vatCategory = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['taux:read', 'taux:write', 'ligne:read'])]
    private string $libelle = '';

    // ⚠ Exposé dans `mapping:read` pour que l'écran des correspondances puisse dire POURQUOI
    // une correspondance est inopérante : un verdict sans cause envoie chercher.
    /**
     * L'entrée du référentiel légal dont ce taux est issu — `null` pour les taux saisis à la main.
     *
     * ⚠ NULLABLE, ET CE N'EST PAS UNE FACILITÉ. Trente et un taux existaient déjà en base avant ce
     * référentiel ; leur inventer une origine serait affirmer une filiation que personne n'a
     * établie. `null` dit la vérité sur eux : on ne sait pas d'où ils viennent, et c'est justement
     * le problème que le référentiel résout pour la suite.
     *
     * ⚠ EN LECTURE SEULE POUR LE CLIENT. La filiation se pose a la reprise, par
     * `AdoptLegalVatRateProcessor`, qui lit le referentiel a la source. Laisser l'ecran l'ecrire
     * supposerait que le referentiel soit une ressource exposee — il ne l'est volontairement
     * pas — et permettrait d'affirmer une filiation avec une valeur qui ne correspond pas.
     *
     * ⚠ ET CE LIEN NE DOIT JAMAIS DEVENIR LA SOURCE DU TAUX. La valeur reste dans `$taux`, sur
     * cette ligne. Faire lire `origineLegale->getRate()` ferait dépendre d'une table externe une
     * valeur que les deux chaînes de scellement NF525 embarquent — c'est exactement le défaut qu'on
     * ne veut pas aggraver.
     */
    #[ORM\ManyToOne(targetEntity: LegalVatRate::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['taux:read'])]
    private ?LegalVatRate $origineLegale = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['taux:read', 'taux:write', 'mapping:read'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProfilExploitant(): ?ProfilExploitant
    {
        return $this->profilExploitant;
    }

    public function setProfilExploitant(?ProfilExploitant $profilExploitant): self
    {
        $this->profilExploitant = $profilExploitant;

        return $this;
    }

    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->profilExploitant?->getEtablissementPrincipal();
    }

    public function getVatCategory(): ?VatCategory
    {
        return $this->vatCategory;
    }

    public function setVatCategory(?VatCategory $vatCategory): self
    {
        $this->vatCategory = $vatCategory;

        return $this;
    }

    public function getTaux(): string
    {
        return $this->taux;
    }

    public function setTaux(string $taux): self
    {
        $this->taux = $taux;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    public function getOrigineLegale(): ?LegalVatRate
    {
        return $this->origineLegale;
    }

    public function setOrigineLegale(?LegalVatRate $origineLegale): self
    {
        $this->origineLegale = $origineLegale;

        return $this;
    }
}
