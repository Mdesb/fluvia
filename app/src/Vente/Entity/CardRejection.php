<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Caisse\Entity\PointDeVente;
use App\Organisation\Entity\Etablissement;
use App\Vente\Enum\StatutTPE;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Un refus de carte, **écrit avant d'être annoncé** (PAY-3).
 *
 * **Pourquoi une table et pas seulement un événement.** Le contrat demandé par `claude-D` ne portait
 * qu'un message sur le bus. Mais elle a elle-même établi que la bascule carte → prélèvement **n'a
 * aucun client aujourd'hui** : aucun débit récurrent sur carte n'existe dans le produit. Son abonné
 * est donc le seul consommateur, et il n'agira sur **aucun** refus. Publier sans écrire ne laisserait
 * donc littéralement aucune trace de la totalité des refus de carte — pas « en cas de panne », mais
 * dans le fonctionnement normal, dès le premier jour.
 *
 * **Et un refus de carte n'est pas une notification, c'est un fait d'exploitation.** Un exploitant
 * voudra les compter : combien ce mois-ci, sur quel point de vente, à quelle heure. Un client
 * contestera un prélèvement en affirmant que sa carte n'a jamais été refusée. Ni l'une ni l'autre de
 * ces questions n'a de réponse si le fait ne vit que dans un message.
 *
 * **Et la raison la plus forte n'est pas la mienne — elle vient de `claude-D`, qui consomme.** Le
 * prélèvement qu'un client contestera, **c'est le sien** : sa bascule le crée à partir de ce refus.
 * Ce qu'elle peut produire pour le défendre, c'est un préavis — il prouve qu'on a prévenu, il ne
 * prouve pas **pourquoi** on a prélevé. Sans cette ligne, le fait générateur n'existe nulle part, et
 * elle se retrouve à avoir prélevé quelqu'un sur la foi d'un message disparu après traitement.
 *
 * **Cette table est donc la pièce justificative d'un prélèvement SEPA**, pas un confort d'exploitation
 * ajouté à un événement. Ni elle ni moi ne l'avions vu en écrivant le contrat ; on l'aurait découvert
 * à la première contestation, c'est-à-dire au pire moment.
 *
 * **L'événement référence cette ligne, il ne la porte pas.** L'ordre compte : on écrit, puis on
 * annonce. L'inverse laisserait un abonné référencer une trace qui n'existe pas encore.
 *
 * **Ce n'est pas une opération scellée**, et c'est délibéré : un refus ne crée aucun `Paiement`, ne
 * déplace aucun argent et n'entre dans aucun total. Le sceller encombrerait la chaîne NF525 de faits
 * qui ne la concernent pas. Elle reste néanmoins immuable — un refus constaté ne se corrige pas, il
 * se complète par un second essai qui est un autre fait.
 *
 * ⚠ **Aucun numéro de carte, aucun jeton.** `Vente` n'en stocke pas : la donnée n'existe pas, donc
 * elle ne peut pas fuir par distraction. Seule la référence rendue par le terminal est conservée, et
 * elle est nulle sur un refus dans la quasi-totalité des cas.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sale_card_rejection')]
#[ORM\Index(name: 'idx_card_rejection_etab_date', columns: ['etablissement_id', 'date_heure'])]
#[ApiResource(
    shortName: 'RefusCarte',
    operations: [
        // `vente.lire` : compter les refus fait partie de la lecture d'exploitation. Aucune écriture
        // n'est exposée — la ligne naît d'un fait constaté par le terminal, pas d'une saisie.
        new GetCollection(security: "is_granted('PERM', 'vente.lire')"),
        new Get(security: "is_granted('PERM', 'vente.lire')"),
    ],
    normalizationContext: ['groups' => ['refus_carte:read']],
    order: ['dateHeure' => 'DESC'],
)]
#[ApiFilter(DateFilter::class, properties: ['dateHeure'])]
#[ApiFilter(OrderFilter::class, properties: ['dateHeure'], arguments: ['orderParameterName' => 'order'])]
class CardRejection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['refus_carte:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['refus_carte:read'])]
    private ?Vente $vente = null;

    /**
     * Le point de vente où la carte a été présentée.
     *
     * Il tient lieu de « terminal » : le dépôt ne donne aucune identité propre aux TPE — `PointDeVente`
     * porte une **liste** de terminaux en configuration, et `ResultatTpe` ne dit pas lequel a répondu.
     * Écrire un identifiant de terminal supposerait de l'inventer. Signalé plutôt que fabriqué.
     */
    #[ORM\ManyToOne(targetEntity: PointDeVente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['refus_carte:read'])]
    private ?PointDeVente $pointDeVente = null;

    #[ORM\Column(length: 32)]
    #[Groups(['refus_carte:read'])]
    private string $moyenCode = '';

    /** Le montant refusé, en centimes entiers — la convention du dépôt, `bcmath` n'étant pas installé. */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['refus_carte:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(length: 16, enumType: StatutTPE::class)]
    #[Groups(['refus_carte:read'])]
    private StatutTPE $statutTpe = StatutTPE::Refuse;

    /** Référence rendue par le terminal, quand il en rend une. Jamais un numéro de carte. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['refus_carte:read'])]
    private ?string $refTpe = null;

    /** Réf. logique Client (M4), nulle sur une vente anonyme — cas normal au guichet (US-L2-05). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['refus_carte:read'])]
    private ?Uuid $client = null;

    /** L'instant du refus, jamais celui du traitement (D37) : c'est le fait qui est daté. */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['refus_carte:read'])]
    private \DateTimeImmutable $dateHeure;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateHeure = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVente(): ?Vente
    {
        return $this->vente;
    }

    public function setVente(?Vente $vente): self
    {
        $this->vente = $vente;

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

    public function getMoyenCode(): string
    {
        return $this->moyenCode;
    }

    public function setMoyenCode(string $moyenCode): self
    {
        $this->moyenCode = $moyenCode;

        return $this;
    }

    public function getMontantCentimes(): int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }

    public function getStatutTpe(): StatutTPE
    {
        return $this->statutTpe;
    }

    public function setStatutTpe(StatutTPE $statutTpe): self
    {
        $this->statutTpe = $statutTpe;

        return $this;
    }

    public function getRefTpe(): ?string
    {
        return $this->refTpe;
    }

    public function setRefTpe(?string $refTpe): self
    {
        $this->refTpe = $refTpe;

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

    public function getDateHeure(): \DateTimeImmutable
    {
        return $this->dateHeure;
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
