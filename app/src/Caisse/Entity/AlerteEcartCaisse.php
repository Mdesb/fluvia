<?php

declare(strict_types=1);

namespace App\Caisse\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Alerte d'écart de caisse (US-CAISSEZ-02, RG-CAISSEZ-05/06/07) : créée automatiquement par
 * `CloturerSessionProcessor` quand `|ClotureZ.ecartTotal| > PointDeVente.toleranceEcartCaisse`, dans
 * la même transaction que la clôture (jamais en différé). Signal interne — n'expose pas le détail par
 * moyen de paiement (réservé à `caisse.voir_z` via `ClotureZ`). Immuable : aucune opération
 * d'écriture exposée par l'API (pas de Post/Patch/Delete) ; au plus une alerte par clôture.
 */
#[ORM\Entity]
#[ORM\Table(name: 'caisse_alerte_ecart')]
#[ORM\Index(name: 'idx_alerte_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_alerte_etablissement', columns: ['etablissement_id'])]
#[ORM\Index(name: 'idx_alerte_auteur', columns: ['auteur_cloture_id'])]
#[ApiResource(
    shortName: 'AlerteEcartCaisse',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caisse.voir_ecart')"),
        new Get(security: "is_granted('PERM', 'caisse.voir_ecart')"),
    ],
    normalizationContext: ['groups' => ['alerte_ecart:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'session' => 'exact'])]
class AlerteEcartCaisse
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['alerte_ecart:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ClotureZ::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['alerte_ecart:read'])]
    private ?ClotureZ $cloture = null;

    /** Dénormalisé de `cloture.session` pour lecture directe (§1.1 plan). */
    #[ORM\ManyToOne(targetEntity: SessionCaisse::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['alerte_ecart:read'])]
    private ?SessionCaisse $session = null;

    /** Dénormalisé pour le cloisonnement (RG-SOCLE-05), même patron que `DemandeEscalade.etablissement`. */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['alerte_ecart:read'])]
    private ?Etablissement $etablissement = null;

    /** = `ClotureZ.ecartTotal` figé à la création (signé : positif = excédent, négatif = manque). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['alerte_ecart:read'])]
    private string $ecartMontant = '0.00';

    /** = `PointDeVente.toleranceEcartCaisse` figée à l'instant T (RG-CAISSEZ-06). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['alerte_ecart:read'])]
    private string $toleranceAppliquee = '0.00';

    /** Qui a déclenché la clôture (peut être le caissier bas niveau). */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['alerte_ecart:read'])]
    private ?Utilisateur $auteurCloture = null;

    /** = horodatage de la `ClotureZ` associée (fixé explicitement par le processor). */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['alerte_ecart:read'])]
    private \DateTimeImmutable $horodatage;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCloture(): ?ClotureZ
    {
        return $this->cloture;
    }

    public function setCloture(?ClotureZ $cloture): self
    {
        $this->cloture = $cloture;

        return $this;
    }

    public function getSession(): ?SessionCaisse
    {
        return $this->session;
    }

    public function setSession(?SessionCaisse $session): self
    {
        $this->session = $session;

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

    public function getEcartMontant(): string
    {
        return $this->ecartMontant;
    }

    public function setEcartMontant(string $ecartMontant): self
    {
        $this->ecartMontant = $ecartMontant;

        return $this;
    }

    public function getToleranceAppliquee(): string
    {
        return $this->toleranceAppliquee;
    }

    public function setToleranceAppliquee(string $toleranceAppliquee): self
    {
        $this->toleranceAppliquee = $toleranceAppliquee;

        return $this;
    }

    public function getAuteurCloture(): ?Utilisateur
    {
        return $this->auteurCloture;
    }

    public function setAuteurCloture(?Utilisateur $auteurCloture): self
    {
        $this->auteurCloture = $auteurCloture;

        return $this;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }
}
