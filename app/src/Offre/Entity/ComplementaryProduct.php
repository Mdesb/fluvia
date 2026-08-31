<?php

declare(strict_types=1);

namespace App\Offre\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Offre\Enum\ComplementMode;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * UN PRODUIT QUI EN APPELLE UN AUTRE — LE CASIER AVEC L'ENTRÉE, LE BONNET AVEC LE COURS.
 *
 * ── POURQUOI CE LIEN EXISTE ALORS QUE LES OPTIONS EXISTENT DÉJÀ ─────────────────────────────────
 *
 * `App\OptionProduit` sait déjà attacher un supplément à un produit : impact prix en montant ou en
 * pourcentage, décrément d'un article de stock, obligatoire ou non, choix unique ou multiple. Un
 * casier à 2 €, une serviette qui sort du stock : tout cela se fait **sans ce fichier**.
 *
 * ⚠ MAIS UNE OPTION N'EST PAS UN PRODUIT, ET LA CONSÉQUENCE EST COMPTABLE. Vérifié dans
 * `AjoutLigneHandler` : l'option ne crée pas sa propre ligne, elle modifie le prix unitaire de la
 * ligne du parent (`setImpactOptionsUnitaire`). La ligne entière porte donc la catégorie comptable
 * du parent — **donc son taux de TVA et son compte**. Une serviette à 20 % vendue en option d'une
 * entrée à 5,5 % serait comptabilisée à 5,5 %. Sur une vente isolée personne ne le voit ; sur un
 * exercice, l'expert-comptable si.
 *
 * ── CE QUI DÉCIDE ENTRE UNE OPTION ET UN COMPLÉMENT ─────────────────────────────────────────────
 *
 * Le complément est un **produit entier** : sa grille tarifaire, sa catégorie comptable, sa TVA, son
 * billet éventuel, son droit d'accès — et il fait **sa propre ligne** dans la vente. On choisit ce
 * lien plutôt qu'une option dès que l'une de ces quatre choses est vraie :
 *
 *   · il a son propre taux de TVA ou son propre compte ;
 *   · il a son propre tarif (enfant, réduit, abonné) ;
 *   · il produit un billet ou ouvre un accès ;
 *   · il doit pouvoir être vendu seul aussi.
 *
 * Sinon, l'option reste le bon outil et coûte moins cher. **On ne remplace rien** : les deux
 * mécanismes répondent à deux questions différentes, et confondre les deux se paierait à la clôture.
 *
 * ── LE SENS DU LIEN N'EST PAS SYMÉTRIQUE ────────────────────────────────────────────────────────
 *
 * « L'entrée appelle le casier » ne dit rien de « le casier appelle l'entrée ». Le casier se vend
 * seul à quelqu'un qui a déjà son entrée. Deux lignes sont donc nécessaires pour un lien
 * réciproque, et ce sera presque toujours faux d'en poser deux.
 */
#[ApiResource(
    shortName: 'ComplementaryProduct',
    normalizationContext: ['groups' => ['complement:read']],
    denormalizationContext: ['groups' => ['complement:write']],
    operations: [
        new GetCollection(security: "is_granted('PERM', 'offre.lire')"),
        new Post(security: "is_granted('PERM', 'offre.modifier')"),
        // Pas de `Patch` : changer le mode revient a retirer le lien et a le reposer. Une operation
        // qu'aucun ecran n'appelle coute un cloisonnement a tenir et un test a maintenir, pour
        // personne.
        new Delete(security: "is_granted('PERM', 'offre.modifier')"),
    ],
)]
// Le filtre est DECLARE, et sa propriete existe au mapping : un filtre sur une propriete inconnue
// est ignore en silence par API Platform, le parametre est accepte, et la collection sort ENTIERE.
#[ApiFilter(SearchFilter::class, properties: ['product' => 'exact', 'complement' => 'exact'])]
#[ORM\Entity]
#[ORM\Table(name: 'off_complementary_product')]
#[ORM\UniqueConstraint(name: 'uniq_complementary_product_pair', columns: ['product_id', 'complement_id'])]
#[ORM\Index(name: 'idx_complementary_product_source', columns: ['product_id'])]
class ComplementaryProduct
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['complement:read'])]
    private Uuid $id;

    /** Le produit qui appelle le complément — celui qu'on ajoute d'abord à la vente. */
    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['complement:read', 'complement:write'])]
    private ?Produit $product = null;

    /**
     * Le produit appelé.
     *
     * ⚠ `onDelete: CASCADE` ici aussi : si le complément disparaît du catalogue, le lien n'a plus
     * d'objet. Le laisser derrière ferait proposer à la caisse un produit qui n'existe plus — et
     * `Required` rendrait alors la vente du parent **impossible**, sans que rien n'explique pourquoi.
     */
    #[ORM\ManyToOne(targetEntity: Produit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['complement:read', 'complement:write'])]
    private ?Produit $complement = null;

    #[ORM\Column(length: 16, enumType: ComplementMode::class, options: ['default' => 'suggested'])]
    #[Groups(['complement:read', 'complement:write'])]
    private ComplementMode $mode = ComplementMode::Suggested;

    /**
     * Combien on en propose par défaut.
     *
     * Une famille de quatre qui prend une entrée prend quatre casiers ; l'agent corrige si besoin.
     * Le défaut est 1 parce que c'est le cas le plus fréquent, pas parce que c'est toujours juste.
     */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive]
    #[Groups(['complement:read', 'complement:write'])]
    private int $defaultQuantity = 1;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduct(): ?Produit
    {
        return $this->product;
    }

    public function setProduct(?Produit $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getComplement(): ?Produit
    {
        return $this->complement;
    }

    public function setComplement(?Produit $complement): self
    {
        $this->complement = $complement;

        return $this;
    }

    public function getMode(): ComplementMode
    {
        return $this->mode;
    }

    public function setMode(ComplementMode $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getDefaultQuantity(): int
    {
        return $this->defaultQuantity;
    }

    public function setDefaultQuantity(int $defaultQuantity): self
    {
        $this->defaultQuantity = $defaultQuantity;

        return $this;
    }

    /**
     * Un produit ne peut pas s'appeler lui-même.
     *
     * ⚠ Sans cette garde, un lien réflexif rendrait un produit `Required` de lui-même — donc
     * invendable, puisque sa présence dans la vente exigerait sa présence. La validation le refuse
     * ici plutôt qu'au moment de la vente : un catalogue qui accepte l'absurde le fait découvrir à
     * la caisse, devant un client.
     */
    #[Assert\Callback]
    public function verifierNonReflexif(\Symfony\Component\Validator\Context\ExecutionContextInterface $contexte): void
    {
        if ($this->product === null || $this->complement === null) {
            return;
        }

        if ($this->product->getId()->equals($this->complement->getId())) {
            $contexte->buildViolation('Un produit ne peut pas être son propre complément.')
                ->atPath('complement')
                ->addViolation();
        }
    }
}
