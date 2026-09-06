<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\State\MarquerImpayeeRegieProcessor;
use App\Securite\Entity\Utilisateur;
use App\Compta\State\SettleUnpaidSaleProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Marquage anti-double-comptabilisation (RG-M6-09) : une recette de régie déjà transmise via
 * PES/régie est exclue/signalée dans l'agrégat e-reporting.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_vente_impayee_regie')]
#[ORM\UniqueConstraint(name: 'uniq_impayee_vente', columns: ['vente_origine'])]
#[ApiResource(
    shortName: 'VenteImpayeeRegie',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/compta/ventes/{id}/marquer-impayee-regie',
            read: false,
            input: false,
            security: "is_granted('PERM', 'compta.gerer')",
            processor: MarquerImpayeeRegieProcessor::class,
        ),
        // LE CHEMIN DU RETOUR, QUI N'EXISTAIT PAS.
        //
        // ⚠ MARQUER ÉTAIT DÉFINITIF, ET PAS SEULEMENT À L'ÉCRAN. `GenerateurEReportingHandler`
        //   exclut de la déclaration DGFiP toutes les ventes marquées, par un `findAll()` sans
        //   statut. Un chèque finalement encaissé restait exclu pour toujours : une recette réelle,
        //   recouvrée, que rien ne pouvait faire redéclarer.
        //
        // ⚠ ON RÈGLE, ON NE DÉMARQUE PAS. Le marquage a eu lieu et reste vrai : le chèque est bien
        //   revenu impayé ce jour-là. L'effacer réécrirait l'histoire ; le régler la continue, et
        //   `AuditWriteSubscriber` garde les deux gestes.
        new Post(
            uriTemplate: '/compta/ventes-impayees-regie/{id}/regler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'compta.gerer')",
            processor: SettleUnpaidSaleProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['impaye:read']],
)]
class VenteImpayeeRegie
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['impaye:read'])]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['impaye:read'])]
    private Uuid $venteOrigine;

    #[ORM\Column(length: 255)]
    #[Groups(['impaye:read'])]
    private string $motif = '';

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['impaye:read'])]
    private \DateTimeImmutable $dateMarquage;

    /**
     * QUAND LA VENTE A FINALEMENT ÉTÉ ENCAISSÉE — `null` tant qu'elle ne l'est pas.
     *
     * ⚠ C'EST CE CHAMP QUI DÉCIDE DE L'E-REPORTING. `GenerateurEReportingHandler` n'exclut plus que
     * les marquages NON RÉGLÉS : une vente recouvrée redevient déclarable, ce qu'aucun geste du
     * produit ne permettait. Sans lui, la déclaration DGFiP perdait définitivement une recette
     * réelle, et personne ne pouvait la lui rendre.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['impaye:read'])]
    private ?\DateTimeImmutable $regleLe = null;

    /**
     * COMMENT ELLE A ÉTÉ ENCAISSÉE.
     *
     * Exigé, comme le motif du marquage : un mouvement qui rentre dans la déclaration fiscale par un
     * geste manuel doit dire par quel moyen, sinon le rapprochement se fait de mémoire.
     */
    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['impaye:read'])]
    private ?string $motifReglement = null;

    /** Qui a constaté l'encaissement. Un geste qui remet une recette dans une déclaration a un auteur. */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['impaye:read'])]
    private ?Utilisateur $regleParUtilisateur = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->venteOrigine = Uuid::v4();
        $this->dateMarquage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVenteOrigine(): Uuid
    {
        return $this->venteOrigine;
    }

    public function estReglee(): bool
    {
        return $this->regleLe !== null;
    }

    public function getRegleLe(): ?\DateTimeImmutable
    {
        return $this->regleLe;
    }

    public function setRegleLe(?\DateTimeImmutable $regleLe): self
    {
        $this->regleLe = $regleLe;

        return $this;
    }

    public function getMotifReglement(): ?string
    {
        return $this->motifReglement;
    }

    public function setMotifReglement(?string $motifReglement): self
    {
        $this->motifReglement = $motifReglement;

        return $this;
    }

    public function getRegleParUtilisateur(): ?Utilisateur
    {
        return $this->regleParUtilisateur;
    }

    public function setRegleParUtilisateur(?Utilisateur $regleParUtilisateur): self
    {
        $this->regleParUtilisateur = $regleParUtilisateur;

        return $this;
    }

    public function setVenteOrigine(Uuid $venteOrigine): self
    {
        $this->venteOrigine = $venteOrigine;

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

    public function getDateMarquage(): \DateTimeImmutable
    {
        return $this->dateMarquage;
    }
}
