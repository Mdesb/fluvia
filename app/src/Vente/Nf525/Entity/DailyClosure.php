<?php

declare(strict_types=1);

namespace App\Vente\Nf525\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Caisse\Entity\PointDeVente;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Nf525\Dto\PendingClosure;
use App\Vente\State\PendingClosuresProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Clôture journalière NF525 (D57) — **un arrêté de totaux cumulés, pas un comptage de tiroir**.
 *
 * **Ce que ce n'est pas.** Ce n'est pas la clôture Z. Le Z ferme une *session de caisse* : il compte
 * du liquide, constate un écart entre théorique et compté, et fige un fonds. Il n'a de sens que là où
 * quelqu'un tient un tiroir. Les deux notions ont été confondues dans ce dépôt parce que, jusqu'à la
 * vente directe, **tout passait par une caisse** — le Z faisait office de clôture quotidienne sans que
 * personne ait à décider que c'en était une.
 *
 * D57 : *deux notions qui coïncident tant qu'un seul cas existe finissent par être représentées par
 * une seule ; le jour où le second cas arrive, ce n'est pas une extension qu'il faut, c'est une
 * séparation.* Un point de vente de vente directe n'a pas de session, donc pas de Z, donc **pas de
 * clôture quotidienne** — un défaut qui ne se serait vu qu'au premier contrôle.
 *
 * **À quoi sert un total cumulé perpétuel.** `grandTotal` n'est pas un chiffre de gestion : c'est le
 * mécanisme qui rend une suppression **détectable**. Chaque clôture reprend le cumul de la précédente
 * et y ajoute la journée. Supprimer une vente d'hier laisserait donc le cumul d'hier plus grand que la
 * somme des ventes qui restent — l'écart se voit sans qu'on ait à savoir ce qui manquait. C'est pour
 * cette raison que le cumul ne déduit **pas** les avoirs : il totalise ce que la chaîne a scellé, pas
 * un résultat comptable. Le résultat se calcule ailleurs ; ici on prouve qu'il ne manque rien.
 *
 * **Immuable, comme tout ce qui est scellé.** Aucune opération d'écriture n'est exposée, et
 * `Nf525\InalterabiliteListener` refuse toute modification ORM d'une opération scellée. Une clôture
 * qu'on pourrait rejouer ne prouverait rien — c'est l'argument de D45 sur la vente validée, et il vaut
 * mot pour mot ici.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nf525_daily_closure')]
// Une seule clôture par journée et par point de vente. Sans cette contrainte, deux clôtures du même
// jour compteraient deux fois la même journée dans le cumul perpétuel — c'est-à-dire que le mécanisme
// censé détecter une disparition **fabriquerait** un excédent. Le contrôle est aussi en code
// (`DailyClosureHandler`), mais un contrôle applicatif ne survit pas à une insertion concurrente.
#[ORM\UniqueConstraint(name: 'uniq_daily_closure_pdv_jour', columns: ['point_de_vente_id', 'business_day'])]
#[ApiResource(
    shortName: 'ClotureJournaliere',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'vente.lire')"),
        new Get(security: "is_granted('PERM', 'vente.lire')"),
        // D57/D55 — la file des journees non closes. Le detecteur existait deja (le refus « journee
        // sautee ») mais il ne parlait qu a celui qui tentait une cloture : une journee oubliee
        // restait invisible jusqu a ce que quelqu un bute dessus, des semaines plus tard. Ici elle se
        // lit sans qu on ait rien tente, et la liste descend a zero.
        new GetCollection(
            uriTemplate: '/clotures-journalieres/en-attente',
            description: 'Journees porteuses de ventes jamais arretees, la plus ancienne d abord (le cumul refuse qu on saute une journee).',
            security: "is_granted('PERM', 'vente.lire')",
            output: PendingClosure::class,
            normalizationContext: ['groups' => ['pending_closure:read']],
            provider: PendingClosuresProvider::class,
            paginationEnabled: false,
        ),
    ],
    normalizationContext: ['groups' => ['daily_closure:read']],
)]
class DailyClosure
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['daily_closure:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PointDeVente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['daily_closure:read'])]
    private ?PointDeVente $pointDeVente = null;

    /** La journée d'exploitation arrêtée. Une date, pas un instant : c'est un jour qu'on clôt. */
    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['daily_closure:read'])]
    private \DateTimeImmutable $businessDay;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['daily_closure:read'])]
    private int $salesCount = 0;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['daily_closure:read'])]
    private string $dailyTotal = '0.00';

    /**
     * Le cumul de la clôture précédente, recopié. **C'est ce qui fait de la suite une chaîne** : la
     * clôture du jour affirme d'où elle part, donc un trou entre deux clôtures se lit sans avoir à
     * rejouer l'historique.
     */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['daily_closure:read'])]
    private string $previousGrandTotal = '0.00';

    /** Total cumulé perpétuel des ventes scellées de ce point de vente, avoirs non déduits. */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['daily_closure:read'])]
    private string $grandTotal = '0.00';

    /**
     * Numéro du dernier maillon de la chaîne d'empreintes au moment de l'arrêté.
     *
     * Il ancre la clôture **dans la chaîne** et non seulement dans la table des ventes : au contrôle,
     * on peut vérifier que les totaux annoncés couvrent exactement les opérations scellées jusqu'à ce
     * rang. Nul si rien n'a encore été scellé sur ce point de vente.
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    #[Groups(['daily_closure:read'])]
    private ?int $lastSequence = null;

    /** L'instant de l'arrêté, jamais la fin de la journée arrêtée (D45 : la date est celle du geste). */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['daily_closure:read'])]
    private \DateTimeImmutable $closedAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['daily_closure:read'])]
    private ?Utilisateur $author = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->businessDay = new \DateTimeImmutable('today');
        $this->closedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getBusinessDay(): \DateTimeImmutable
    {
        return $this->businessDay;
    }

    public function setBusinessDay(\DateTimeImmutable $businessDay): self
    {
        $this->businessDay = $businessDay;

        return $this;
    }

    public function getSalesCount(): int
    {
        return $this->salesCount;
    }

    public function setSalesCount(int $salesCount): self
    {
        $this->salesCount = $salesCount;

        return $this;
    }

    public function getDailyTotal(): string
    {
        return $this->dailyTotal;
    }

    public function setDailyTotal(string $dailyTotal): self
    {
        $this->dailyTotal = $dailyTotal;

        return $this;
    }

    public function getPreviousGrandTotal(): string
    {
        return $this->previousGrandTotal;
    }

    public function setPreviousGrandTotal(string $previousGrandTotal): self
    {
        $this->previousGrandTotal = $previousGrandTotal;

        return $this;
    }

    public function getGrandTotal(): string
    {
        return $this->grandTotal;
    }

    public function setGrandTotal(string $grandTotal): self
    {
        $this->grandTotal = $grandTotal;

        return $this;
    }

    public function getLastSequence(): ?int
    {
        return $this->lastSequence;
    }

    public function setLastSequence(?int $lastSequence): self
    {
        $this->lastSequence = $lastSequence;

        return $this;
    }

    public function getClosedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getAuthor(): ?Utilisateur
    {
        return $this->author;
    }

    public function setAuthor(?Utilisateur $author): self
    {
        $this->author = $author;

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
}
