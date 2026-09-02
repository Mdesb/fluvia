<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use App\Compta\Enum\VatRateCategory;
use App\Compta\Repository\LegalVatRateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * ⚠ CETTE ENTITE N'EST PAS EXPOSEE PAR L'API, ET C'EST UNE DECISION, PAS UN OUBLI.
 *
 * Le referentiel est global a dessein : un taux hongrois est un fait de droit, il ne se cloisonne
 * pas. Or le garde-fou de couverture de perimetre refuse — a juste titre — toute ENTITE exposee que
 * rien ne peut filtrer, et son cliquet est opposable : le plafond ne remonte pas face a `main`.
 *
 * Lui inventer un champ `etablissement` aurait produit « un cloisonnement qui filtre a cote, pire
 * qu'une absence de filtre parce qu'il rassure » — les mots de `AccountingScopeExtension`. Faire
 * remonter le plafond aurait desserre un cliquet de cloisonnement pour une commodite d'ecran.
 *
 * Ce qu'on expose est donc une VUE, `App\Compta\ApiResource\VatRateCatalog` : « les taux
 * applicables ici », contextuelle par construction. La table reste globale ; la question posee a un
 * contexte. Ce n'est pas un contournement du garde-fou — c'est ce qu'il demandait, formule comme il
 * fallait.
 *
 * UN TAUX DE TVA TEL QUE LA LOI LE FIXE — pas tel qu'un exploitant le saisit.
 *
 * Demandé par Maxime : « les taux de TVA sont définis par les lois, un utilisateur n'a pas besoin
 * de le créer, il faut qu'on les propose tous automatiquement, et pour tous les pays d'Europe ».
 * Mesuré avant d'écrire : 31 taux existaient déjà en base pour un seul jeu de démonstration à trois
 * établissements — chacun retape ce que la loi a déjà écrit, et ça prolifère.
 *
 * ── CETTE TABLE EST IMMUABLE, ET C'EST UNE DÉCISION DE MAXIME, PAS UNE PRÉCAUTION D'AUTEUR ──────
 *
 * Aucun `Patch`, aucun `Delete`, aucun groupe d'écriture. Un taux qui change par décret ne se
 * modifie pas ici : il **clôt** l'entrée en cours (`validUntil`) et en ouvre une neuve. La raison
 * est mesurée, pas théorique — les deux chaînes de scellement NF525 du produit
 * (`ScellementFactureHandler:127`, `ScellementEcritureHandler:120`) scellent la VALEUR d'un taux lu
 * par référence. Modifier un taux existant y fait échouer la vérification d'intégrité sur des
 * documents que personne n'a touchés, avec le message « la donnée a été altérée » — une accusation
 * de falsification portée contre l'utilisateur pour un geste que le logiciel autorise.
 *
 * Ce défaut-là est traité ailleurs (figer la valeur sur la ligne à l'émission). Ce qui est décidé
 * ICI est de ne pas l'aggraver : le référentiel n'offrira jamais de raison de modifier un taux
 * auquel une écriture scellée renvoie.
 *
 * ── POURQUOI UNE TABLE SÉPARÉE DE `TauxTva`, ET NON UN CHAMP DE PLUS ────────────────────────────
 *
 * `TauxTva` est ce que l'exploitant utilise : il porte son profil, il est modifiable, il est
 * référencé par les lignes de facture et par le plan comptable. Y ajouter un pays et des dates
 * mélangerait deux choses de nature différente — ce que la loi dit, et ce que cet exploitant-là a
 * choisi d'employer. La première est universelle et figée ; la seconde est locale et vivante.
 *
 * Le lien va donc dans ce sens : un `TauxTva` PEUT désigner l'entrée légale dont il est issu
 * (`TauxTva::$origineLegale`), et rien n'oblige à ce qu'il en ait une — les taux saisis avant ce
 * référentiel restent valides et sans origine, ce qui est la vérité sur eux.
 */
#[ORM\Entity(repositoryClass: LegalVatRateRepository::class)]
#[ORM\Table(name: 'accounting_legal_vat_rate')]
#[ORM\UniqueConstraint(name: 'uniq_legal_vat_rate', columns: ['country', 'territory', 'category', 'valid_from'])]
#[ORM\Index(name: 'idx_legal_vat_rate_country', columns: ['country', 'territory'])]
class LegalVatRate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['legal_vat:read'])]
    private Uuid $id;

    /**
     * Code pays ISO 3166-1 alpha-2, en majuscules — `FR`, `BE`, `DE`.
     *
     * ⚠ Le code plutôt que le nom, parce qu'un nom de pays est une traduction et qu'une clé ne se
     * traduit pas. « Allemagne », « Germany » et « Deutschland » désignent la même TVA.
     */
    #[ORM\Column(length: 2)]
    #[Groups(['legal_vat:read'])]
    private string $country = '';

    /**
     * LE TERRITOIRE FISCAL, A L'INTERIEUR DU PAYS. `''` = le regime de droit commun.
     *
     * ⚠ POURQUOI CE CHAMP EXISTE : UN CODE PAYS NE SUFFIT PAS A DESIGNER UN REGIME.
     *
     * L'import TEDB du 02/09 l'a montre par une violation de contrainte : l'Espagne rend `7,00` ET
     * `21,00` a la meme date. Ce ne sont pas deux versions du meme taux — ce sont les Canaries et la
     * peninsule, deux regimes distincts sous un seul `ES`. Le referentiel n'ayant qu'une place par
     * (pays, categorie, date), l'Espagne n'a pas pu etre importee du tout.
     *
     * Le cas n'a rien d'exotique et nous concerne directement : la Corse et les DOM ont leurs
     * propres taux, Madere et les Acores les leurs, Aland les siens. Un exploitant francais aux
     * Antilles est en `FR` — le champ `pays` de son etablissement le dit deja — et il ne facture pas
     * a 20 %.
     *
     * ── ⚠ NON NUL, ET `''` PLUTOT QUE `NULL` : CE N'EST PAS UN DETAIL DE STYLE ──────────────────
     *
     * MariaDB traite deux `NULL` comme DISTINCTS dans un index unique. Un `territory` nullable
     * aurait donc laisse coexister deux lignes « droit commun » identiques pour un meme pays, une
     * meme categorie et une meme date — la contrainte aurait cesse de proteger exactement le cas
     * courant, celui qu'elle protege aujourd'hui, et sans rien dire.
     *
     * ── CE QUE LE CODE VAUT, ET CE QU'IL NE VAUT PAS ────────────────────────────────────────────
     *
     * C'est un code court et interne (`IC` pour les Canaries, `CORSE`, `DOM`), pas une norme : ISO
     * 3166-2 decoupe des SUBDIVISIONS administratives, qui ne coincident pas avec les territoires
     * FISCAUX. Pretendre le contraire ferait chercher une correspondance qui n'existe pas.
     */
    #[ORM\Column(length: 20, options: ['default' => ''])]
    #[Groups(['legal_vat:read'])]
    private string $territory = '';

    #[ORM\Column(length: 20, enumType: VatRateCategory::class)]
    #[Groups(['legal_vat:read'])]
    private VatRateCategory $category = VatRateCategory::Standard;

    /** Le taux en pourcentage : « 20.00 », « 5.50 », « 0.00 ». */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Groups(['legal_vat:read'])]
    private string $rate = '0.00';

    /**
     * Ce à quoi le taux s'applique, dans la langue du pays concerné quand c'est utile.
     *
     * Pas un libellé technique : c'est ce que l'exploitant lit pour reconnaître SON taux parmi ceux
     * de son pays. « Taux réduit — restauration, transport de voyageurs » lui dit quelque chose ;
     * « FR-REDUCED-2 » ne lui dit rien.
     */
    #[ORM\Column(length: 160)]
    #[Groups(['legal_vat:read'])]
    private string $label = '';

    /**
     * Date d'entrée en vigueur. OBLIGATOIRE, et c'est ce qui rend la table utilisable dans le temps.
     *
     * Une facture de 2025 doit pouvoir être expliquée avec le taux de 2025. Sans cette date, un
     * référentiel ne dit que « ce qui est vrai aujourd'hui » — et devient faux sur tout le passé au
     * premier décret, sans que personne ne s'en aperçoive.
     */
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['legal_vat:read'])]
    private \DateTimeImmutable $validFrom;

    /** Date de fin d'application, `null` tant que le taux est en vigueur. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['legal_vat:read'])]
    private ?\DateTimeImmutable $validUntil = null;

    /**
     * D'où vient cette ligne, en clair — « Directive TVA 2006/112/CE, annexe III » ou « CGI art.
     * 278-0 bis ». Renseigné par le semis, jamais par un écran.
     *
     * ⚠ Sans cette colonne, personne ne peut vérifier une entrée sans refaire la recherche
     * juridique. Une table de taux légaux dont on ne sait pas d'où elle vient est une table
     * d'opinions.
     */
    #[ORM\Column(length: 255)]
    #[Groups(['legal_vat:read'])]
    private string $source = '';

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->validFrom = new \DateTimeImmutable('today');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): self
    {
        $this->country = strtoupper($country);

        return $this;
    }

    public function getCategory(): VatRateCategory
    {
        return $this->category;
    }

    public function setCategory(VatRateCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): self
    {
        $this->rate = $rate;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): self
    {
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /** Le taux s'applique-t-il à cette date ? `validUntil` nul veut dire « toujours en vigueur ». */
    public function appliesOn(\DateTimeImmutable $date): bool
    {
        if ($date < $this->validFrom) {
            return false;
        }

        return null === $this->validUntil || $date <= $this->validUntil;
    }

    public function getTerritory(): string
    {
        return $this->territory;
    }

    public function setTerritory(string $territory): self
    {
        $this->territory = strtoupper(trim($territory));

        return $this;
    }
}
