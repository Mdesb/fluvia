<?php

declare(strict_types=1);

namespace App\Website\Entity;

use App\Website\Enum\PublicationStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un metier du referentiel — une LIGNE, la ou il y avait neuf endroits de code.
 *
 * ── CE QUE CETTE TABLE REMPLACE ────────────────────────────────────────────────────────────────
 *
 * Ajouter un metier demandait de toucher neuf fichiers, et sept de ces oublis n'emettaient aucun
 * signal : sans case dans `Metier`, la structure du client s'ouvrait avec ZERO module ; sans
 * prereglage, un `?? []` lui en donnait deux sur quinze ; sans nom, sa page repondait 200 avec un
 * `<title>` vide. Le detail est dans `features/referentiel-metiers/refs/faits-etablis.md`.
 *
 * ── PAS DE `etablissement_id`, ET C'EST L'EXCEPTION QUI S'ECRIT ────────────────────────────────
 *
 * ⚠ Tout est cloisonne par etablissement dans ce produit ; ce referentiel ne l'est pas. La question
 * « quel etablissement lit cette page ? » n'a pas de reponse : le lecteur est un inconnu qui n'a pas
 * de compte. C'est le catalogue de l'EDITEUR, comme les articles du blog. Ce qui remplace le
 * cloisonnement, c'est le guichet : les operations d'ecriture vivent sous `/editor/**`, garde.
 *
 * ── CE QUE CETTE ENTITE NE PORTE PAS ───────────────────────────────────────────────────────────
 *
 * **Le corps redige de la page** vit deja dans `website_content_block`, sous la cle
 * `metier.<code>.body`. Une colonne `body` ici creerait deux textes pour une meme page, dont un que
 * l'ecran d'administration ne montre pas.
 *
 * **Les arguments de vente** (`MetierCatalog::SPECIFICITES`) restent en code, et c'est delibere :
 * leur docblock impose que chaque ligne NOMME UNE ENTITE DU DEPOT. Les rendre editables, ce serait
 * autoriser une promesse commerciale sans entite derriere — invendable le jour de la demonstration,
 * qui est le seul moment ou le prospect la teste.
 */
#[ORM\Entity]
#[ORM\Table(name: 'website_trade')]
#[ORM\UniqueConstraint(name: 'uniq_website_trade_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_website_trade_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_website_trade_publication', columns: ['status', 'position'])]
class Trade
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * La cle technique, IMMUABLE.
     *
     * ⚠ **SA LONGUEUR EST CALCULEE, PAS CHOISIE.** Elle compose la cle du bloc de contenu
     * `metier.<code>.body`, et `ContentBlock::$blockKey` est un `VARCHAR(80)`. Le prefixe et le
     * suffixe font douze caracteres : au-dela de 68, la cle serait tronquee EN SILENCE et deux
     * metiers finiraient par partager le meme bloc. 64 laisse quatre caracteres de marge.
     */
    #[ORM\Column(length: 64)]
    private string $code = '';

    /**
     * L'identifiant d'URL.
     *
     * ⚠ **SEPARE DU CODE, ET PAS PAR COMMODITE.** Un slug publie est une promesse tenue par
     * quelqu'un d'autre : un moteur l'a indexe, un prospect l'a mis en favori. Le renommer casse
     * ces liens. S'il valait aussi cle de bloc, le renommer detacherait EN PLUS le texte de la page
     * de sa page — deux degats pour un geste.
     */
    #[ORM\Column(length: 120)]
    private string $slug = '';

    /** Le libelle de menu : il doit tenir sur une ligne. */
    #[ORM\Column(length: 160)]
    private string $name = '';

    /**
     * Le titre de la balise `<title>` et des moteurs.
     *
     * Il differe du nom pour la raison deja ecrite dans `MetierCatalog` : un titre de recherche
     * contient les mots qu'on tape, un libelle de menu doit tenir sur une ligne.
     */
    #[ORM\Column(length: 200)]
    private string $searchTitle = '';

    /** Le chapo. `TEXT` parce que le gabarit le tronque lui-meme a 300 pour la meta description. */
    #[ORM\Column(type: 'text')]
    private string $lead = '';

    /**
     * Le rang d'affichage.
     *
     * ⚠ **L'ORDRE ACTUEL N'EST NI ALPHABETIQUE NI ARBITRAIRE** : c'est l'ordre de declaration de
     * l'enumeration `Metier` — piscine, sport, padel, patinoire, musee. La commande de
     * materialisation pose 10, 20, 30, 40, 50 dans cet ordre exact. C'est une condition du rendu
     * identique, pas une preference.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    /**
     * ⚠ **ON REUTILISE L'ENUMERATION DU BLOG plutot que d'en creer une jumelle.** Deux
     * enumerations `draft`/`published` dans le meme module divergeraient au premier troisieme etat.
     *
     * Et **pas de `publishedAt` ici** : l'argument de `BlogPost` — « planifie n'est pas un etat, la
     * date fait le troisieme » — ne s'applique pas. Personne n'a demande de programmer l'ouverture
     * d'une page metier, et un champ sans usage se remplit de travers.
     */
    #[ORM\Column(length: 16, enumType: PublicationStatus::class, options: ['default' => 'draft'])]
    private PublicationStatus $status = PublicationStatus::Draft;

    /** @var Collection<int, TradeActivity> */
    #[ORM\OneToMany(mappedBy: 'trade', targetEntity: TradeActivity::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $activities;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->activities = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
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

    public function getSearchTitle(): string
    {
        return $this->searchTitle;
    }

    public function setSearchTitle(string $searchTitle): self
    {
        $this->searchTitle = $searchTitle;

        return $this;
    }

    public function getLead(): string
    {
        return $this->lead;
    }

    public function setLead(string $lead): self
    {
        $this->lead = $lead;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

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

    /** @return Collection<int, TradeActivity> */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    /**
     * ⚠ **LES DEUX COTES SONT TENUS.** Une collection sans `setTrade()` de l'autre cote accepte
     * l'ajout, ne leve rien, et n'enregistre RIEN : c'est la relation qui porte la colonne, pas la
     * collection. Ce depot a deja mesure cette famille de defauts — des ecritures acceptees qui
     * n'enregistrent pas.
     */
    public function addActivity(TradeActivity $activity): self
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
            $activity->setTrade($this);
        }

        return $this;
    }

    public function removeActivity(TradeActivity $activity): self
    {
        if ($this->activities->removeElement($activity) && $activity->getTrade() === $this) {
            $activity->setTrade(null);
        }

        return $this;
    }
}
