<?php

declare(strict_types=1);

namespace App\Vente\Entity;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Correction de la **ventilation** d'un règlement (D45) : −X sur un moyen, +X sur un autre.
 *
 * Le cas réel : un caissier saisit « espèces » alors que le client a payé par carte. Le montant de la
 * vente n'est pas en cause — **seule sa ventilation l'est**.
 *
 * **Pourquoi ce n'est pas une modification.** `Nf525\InalterabiliteListener` refuse toute écriture sur
 * une opération scellée, et `HashChainSignataire` chaîne les empreintes. Ce n'est pas une politique
 * qu'on pourrait assouplir : le jour où l'on peut réécrire une vente validée, **plus aucune vente
 * n'est probante**, y compris les milliers qui étaient justes.
 *
 * **Pourquoi ce n'est pas un avoir non plus.** Le dépôt sait déjà contre-passer, mais un avoir suivi
 * d'une nouvelle vente **annule et rejoue le chiffre d'affaires** : deux mouvements de résultat pour
 * corriger une erreur qui n'a rien changé au montant. Ici la vente reste intacte et sa somme ne bouge
 * pas ; seule la ventilation par moyen est rectifiée, par une écriture qui s'ajoute.
 *
 * **La date est celle du geste, pas celle de la vente** (D45, trois raisons dans l'ordre de force) :
 * la chaîne NF525 est chronologique et une écriture antidatée la rendrait invérifiable ; la clôture Z
 * de la veille est fermée, et un Z qu'on peut réécrire ne prouve plus rien ; enfin la correction est
 * un fait réel, décidé par quelqu'un aujourd'hui — l'antidater effacerait la seule information qui
 * compte en cas de contrôle, **quand s'en est-on aperçu**.
 *
 * Conséquence assumée, et à dire à l'exploitant : le Z d'hier garde sa ventilation fausse, celui
 * d'aujourd'hui porte la correction. Ce n'est pas un défaut — c'est ce qui rend le Z digne de foi. Et
 * ce n'est pas une perte d'information : la correction pointe la vente d'origine, donc un état par
 * date de vente reste calculable. C'est une question de restitution, pas de donnée.
 */
#[ORM\Entity]
// Nom de table entierement anglais (D5, fichier neuf) : le prefixe habituel du module — `vente_` —
// est du vocabulaire francais herite, et le garde-fou D5 l'a refuse sur un fichier ajoute. Il a
// raison : le retrofit reprendra les anciennes tables, il n'y a pas de raison d'en ajouter une a
// reprendre.
#[ORM\Table(name: 'sale_settlement_correction')]
class SettlementCorrection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['correction:read', 'vente:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['correction:read'])]
    private ?Vente $vente = null;

    /** Moyen dont le montant est retiré — celui que le caissier avait saisi par erreur. */
    #[ORM\Column(length: 32)]
    #[Groups(['correction:read', 'vente:read'])]
    private string $moyenDebite = '';

    /** Moyen sur lequel le montant est reporté — celui qui a réellement servi. */
    #[ORM\Column(length: 32)]
    #[Groups(['correction:read', 'vente:read'])]
    private string $moyenCredite = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['correction:read', 'vente:read'])]
    private string $montant = '0.00';

    /**
     * Obligatoire. Cette permission déplace de l'argent entre moyens : quelqu'un qui peut sortir des
     * espèces vers la carte peut masquer un manquant. Le motif est ce qui rend le geste relisible.
     */
    #[ORM\Column(type: 'text')]
    #[Groups(['correction:read', 'vente:read'])]
    private string $motif = '';

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['correction:read'])]
    private ?Utilisateur $auteur = null;

    /** Le jour du geste (D45). Jamais celui de la vente. */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['correction:read', 'vente:read'])]
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

    public function getMoyenDebite(): string
    {
        return $this->moyenDebite;
    }

    public function setMoyenDebite(string $moyenDebite): self
    {
        $this->moyenDebite = $moyenDebite;

        return $this;
    }

    public function getMoyenCredite(): string
    {
        return $this->moyenCredite;
    }

    public function setMoyenCredite(string $moyenCredite): self
    {
        $this->moyenCredite = $moyenCredite;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

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
