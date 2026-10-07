<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Platform\Filter\UuidReferenceFilter;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Organisation\Entity\Etablissement;
use App\Vente\Enum\StatutVente;
use App\Vente\State\AjoutLigneProcessor;
use App\Vente\State\AnnulerVenteProcessor;
use App\Vente\State\CorrectSettlementProcessor;
use App\Vente\State\CreerVenteProcessor;
use App\Vente\State\ModifierLigneProcessor;
use App\Vente\State\PaiementProcessor;
use App\Vente\State\RattacherClientProcessor;
use App\Vente\State\RembourserVenteProcessor;
use App\Vente\State\RetirerLigneProcessor;
use App\Vente\State\TicketProcessor;
use App\Vente\State\ValiderVenteProcessor;
use App\Vente\State\ViderPanierProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Vente / Ticket (« Commande » au cahier, US-L2-01). Panier composé en session ouverte, encaissé
 * par paiement scindé jusqu'à reste dû = 0 (RG-M2-03), puis validé et scellé (NF525). Après
 * validation, la vente est figée (immuabilité RG-M2-07 / CA-15) ; toute correction passe par
 * contre-passation (avoir/remboursement). Portée par la clé d'idempotence pour la resynchro
 * hors-ligne sans doublon (RG-M2-08).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vente_vente')]
#[ORM\UniqueConstraint(name: 'uniq_vente_numero', columns: ['numero'])]
#[ORM\UniqueConstraint(name: 'uniq_vente_cle_idempotence', columns: ['cle_idempotence'])]
#[ApiResource(
    shortName: 'Vente',
    // D48 — chaque operation ci-dessous est `input: false` et lit pourtant un corps : le contrat
    // n'etait donc lisible QUE dans le processor. `claude-H` y a perdu du temps sur le
    // remboursement, dont le processor lit trois champs et repond trois choses dont une escalade,
    // sans que rien ne l'annonce. Les `description:` remettent le contrat la ou on le cherche.
    operations: [
        new GetCollection(security: "is_granted('PERM', 'vente.lire')"),
        new Get(security: "is_granted('PERM', 'vente.lire')"),
        new Post(
            uriTemplate: '/ventes',
            description: 'Ouvre un panier. Corps : { session, client?, cleIdempotence?, id?, origineHorsLigne? }. Sans session : vente directe — exige le droit vente.vente_directe et refuse ensuite les especes (D44-bis).',
            read: false,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: CreerVenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/lignes',
            description: 'Ajoute une ligne. Corps : { produit, typeTarif, quantite, beneficiaire?, options?, note?, qf?, remiseLigne?, remiseType?, prixForce?/prixUnitaire? (droit vente.forcer_prix) }. Le typeTarif est propre a CHAQUE ligne (D44).',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: AjoutLigneProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/modifier-ligne',
            description: 'Modifie une ligne du panier. Corps : { ligne, quantite?, note? }.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: ModifierLigneProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/retirer-ligne',
            description: 'Retire une ligne du panier. Corps : { ligne }.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: RetirerLigneProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/vider',
            description: 'Vide le panier. Aucun corps.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: ViderPanierProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/client',
            description: 'Rattache un client a la vente. Corps : { client } ou { recherche } ou { creer }.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.creer')",
            processor: RattacherClientProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/paiements',
            description: 'Enregistre un reglement. Corps : { moyen, montant, cleIdempotence?, id?, differe?, banque?, numeroCheque? }. '
                . 'Rejouer le meme appel avec la meme cleIdempotence (ou le meme id) rend le reglement deja enregistre '
                . '(dejaEnregistre: true, 200), sans redemander au terminal ni redebiter le porte-monnaie, meme apres validation. '
                . 'Une cle deja employee sur une autre vente, ou avec un autre moyen ou un autre montant, est refusee (422) avant tout effet.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.encaisser')",
            processor: PaiementProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/valider',
            description: 'Valide la vente et emet les supports. Corps : { supports?: [...] }.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.encaisser')",
            processor: ValiderVenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/annuler',
            description: 'Annule une vente validée par contre-passation (avoir). Corps : { motif ∈ {Erreur de saisie, Client parti, Doublon}, demandeEscalade? }',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.annuler')",
            processor: AnnulerVenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/rembourser',
            description: 'Rembourse une vente validee par contre-passation (Avoir), jamais par modification. Corps : { motif, montant? (partiel, defaut = total), demandeEscalade? (jeton de rejeu) }. Repond soit l Avoir, soit une escalade a autoriser.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.rembourser')",
            processor: RembourserVenteProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/corriger-reglement',
            description: 'Corrige la VENTILATION d un reglement (D45) : -X sur un moyen, +X sur un autre. La vente n est jamais modifiee, l ecriture s ajoute et est scellee, et elle est datee du JOUR DU GESTE. Corps : { moyenDebite, moyenCredite, montant, motif }.',
            read: true,
            input: false,
            output: SettlementCorrection::class,
            normalizationContext: ['groups' => ['correction:read']],
            security: "is_granted('PERM', 'vente.corriger_reglement')",
            processor: CorrectSettlementProcessor::class,
        ),
        new Post(
            uriTemplate: '/ventes/{id}/ticket',
            description: 'Produit le ticket. Corps : { canal?, mode? }.',
            read: true,
            input: false,
            security: "is_granted('PERM', 'vente.lire')",
            processor: TicketProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['vente:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['session' => 'exact', 'statut' => 'exact', 'numero' => 'exact'])]
// D48 — sans ces deux filtres, l'historique des ventes est inutilisable : `claude-H` a refuse de
// contourner en filtrant en memoire, et elle avait raison — un filtre qui ne porterait que sur la
// page chargee ferait conclure a un caissier que sa vente n'existe pas.
//
// L'ordre importe autant que le filtre : sans `OrderFilter`, « les cinquante dernieres ventes » n'est
// meme pas garanti, l'ordre etant celui que la base rend. `date` est le defaut descendant, parce que
// c'est ainsi qu'on lit un historique.
#[ApiFilter(OrderFilter::class, properties: ['date' => 'DESC', 'numero' => 'ASC'], arguments: ['orderParameterName' => 'order'])]
#[ApiFilter(DateFilter::class, properties: ['date'])]
#[ApiFilter(UuidReferenceFilter::class, properties: ['client'])]
class Vente
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['vente:read', 'avoir:read'])]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    #[Groups(['vente:read'])]
    private string $numero = '';

    /**
     * **Nulle sur une vente directe** (D44-bis). La colonne était `NOT NULL` : la vente sans caisse
     * n'était pas interdite par une règle qu'on pouvait assouplir, elle était **impossible au niveau
     * du schéma**. Ce qui reste garanti est plus fort, et se lit sur le champ d'en dessous.
     */
    #[ORM\ManyToOne(targetEntity: SessionCaisse::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['vente:read'])]
    private ?SessionCaisse $session = null;

    /**
     * **Toujours renseigné** — l'invariant qui remplace celui que la session perd (D44-bis).
     *
     * La chaîne NF525 est chaînée **par point de vente**, et `ValiderVenteService` refuse déjà de
     * valider une vente qui n'en a pas. Le porter ici plutôt que de le relire par la session répond à
     * deux besoins : une vente directe n'a pas de session d'où le déduire, et le point de vente qui a
     * scellé une vente est un **fait de son histoire** — le déduire ferait dépendre le passé d'un
     * objet qui, lui, peut encore bouger.
     */
    #[ORM\ManyToOne(targetEntity: PointDeVente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['vente:read'])]
    private ?PointDeVente $pointDeVente = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['vente:read'])]
    private \DateTimeImmutable $date;

    /** Réf. logique Client (M4) — pas de FK dure ; vente anonyme si null (US-L2-05). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['vente:read'])]
    private ?Uuid $client = null;

    #[ORM\Column(length: 16, enumType: StatutVente::class, options: ['default' => 'en_cours'])]
    #[Groups(['vente:read'])]
    private StatutVente $statut = StatutVente::EnCours;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read'])]
    private string $total = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read'])]
    private string $totalRemises = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['vente:read'])]
    private string $resteAPayer = '0.00';

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['vente:read'])]
    private bool $origineHorsLigne = false;

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['vente:read'])]
    private Uuid $cleIdempotence;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['vente:read'])]
    private ?Etablissement $etablissement = null;

    /** Vrai si le ticket a été imprimé (conditionne l'invalidation de support à l'annulation). */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['vente:read'])]
    private bool $imprime = false;

    /** @var Collection<int, LigneVente> */
    #[ORM\OneToMany(targetEntity: LigneVente::class, mappedBy: 'vente', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['vente:read'])]
    private Collection $lignes;

    /** @var Collection<int, Paiement> */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'vente', cascade: ['persist'])]
    #[Groups(['vente:read'])]
    private Collection $paiements;

    /** @var Collection<int, BilletSupport> */
    #[ORM\OneToMany(targetEntity: BilletSupport::class, mappedBy: 'vente', cascade: ['persist'])]
    #[Groups(['vente:read'])]
    private Collection $supports;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->date = new \DateTimeImmutable();
        $this->cleIdempotence = Uuid::v4();
        $this->lignes = new ArrayCollection();
        $this->paiements = new ArrayCollection();
        $this->supports = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getSession(): ?SessionCaisse
    {
        return $this->session;
    }

    /**
     * Pose aussi le point de vente. **C'est volontaire, et c'est ce qui rend la colonne tenable.**
     *
     * Six endroits du dépôt construisent une `Vente` — abonnement en ligne, confirmation de commande,
     * synchronisation hors ligne, réservation, caisse, jeu de données L11. Tous appellent
     * `setSession()`. Exiger en plus un `setPointDeVente()` de chacun aurait fait reposer une colonne
     * `NOT NULL` sur la vigilance de six appelants, dont quatre hors de mon périmètre : elle aurait
     * cassé chez eux, à l'exécution, un jour où personne ne cherchait ça.
     *
     * Une vente directe, qui n'a pas de session, pose le point de vente elle-même.
     */
    public function setSession(?SessionCaisse $session): self
    {
        $this->session = $session;
        if ($session?->getPointDeVente() !== null) {
            $this->pointDeVente = $session->getPointDeVente();
        }

        return $this;
    }

    public function getPointDeVente(): ?PointDeVente
    {
        return $this->pointDeVente;
    }

    public function setPointDeVente(?PointDeVente $pointDeVente): self
    {
        $this->pointDeVente = $pointDeVente;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getClient(): ?Uuid
    {
        return $this->client;
    }

    public function setClient(?Uuid $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getStatut(): StatutVente
    {
        return $this->statut;
    }

    public function setStatut(StatutVente $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function setTotal(string $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getTotalRemises(): string
    {
        return $this->totalRemises;
    }

    public function setTotalRemises(string $totalRemises): self
    {
        $this->totalRemises = $totalRemises;

        return $this;
    }

    public function getResteAPayer(): string
    {
        return $this->resteAPayer;
    }

    public function setResteAPayer(string $resteAPayer): self
    {
        $this->resteAPayer = $resteAPayer;

        return $this;
    }

    public function isOrigineHorsLigne(): bool
    {
        return $this->origineHorsLigne;
    }

    public function setOrigineHorsLigne(bool $origineHorsLigne): self
    {
        $this->origineHorsLigne = $origineHorsLigne;

        return $this;
    }

    public function getCleIdempotence(): Uuid
    {
        return $this->cleIdempotence;
    }

    public function setCleIdempotence(Uuid $cleIdempotence): self
    {
        $this->cleIdempotence = $cleIdempotence;

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

    public function isImprime(): bool
    {
        return $this->imprime;
    }

    public function setImprime(bool $imprime): self
    {
        $this->imprime = $imprime;

        return $this;
    }

    /** @return Collection<int, LigneVente> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneVente $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setVente($this);
        }

        return $this;
    }

    public function removeLigne(LigneVente $ligne): self
    {
        $this->lignes->removeElement($ligne);

        return $this;
    }

    /** @return Collection<int, Paiement> */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiement $paiement): self
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
            $paiement->setVente($this);
        }

        return $this;
    }

    /** @return Collection<int, BilletSupport> */
    public function getSupports(): Collection
    {
        return $this->supports;
    }

    public function addSupport(BilletSupport $support): self
    {
        if (!$this->supports->contains($support)) {
            $this->supports->add($support);
            $support->setVente($this);
        }

        return $this;
    }

    /**
     * Retire un support de la collection. Utilisé par `ValiderVenteService::valider()` pour couper la
     * cascade `persist` lorsqu'un support cree dans la transaction de validation doit etre abandonne
     * apres un rollback SQL (l'UnitOfWork ne se vide pas tout seul) — sinon un flush ulterieur de
     * l'appelant reinsererait un support orphelin.
     */
    public function removeSupport(BilletSupport $support): self
    {
        $this->supports->removeElement($support);

        return $this;
    }

    public function estScellee(): bool
    {
        return $this->statut->estScellee();
    }
}
