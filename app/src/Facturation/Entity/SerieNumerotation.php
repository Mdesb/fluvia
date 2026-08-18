<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Enum\PrefixeSerie;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Compteur de numérotation légale (RG-FACT-01, `plan-facturation.md` §1.4).
 *
 * **Une seule source de vérité** pour la séquence : `(profilExploitant, exercice, préfixe)`. Champ
 * append-only, incrémenté sous verrou pessimiste par `App\Facturation\Service\GenerateurNumeroFacture`
 * **à l'intérieur** de la transaction d'émission. Si l'émission échoue après l'incrément, la
 * transaction est annulée en bloc et **aucun numéro n'est consommé** (interdit les trous, RG-FACT-01).
 *
 * En lecture seule côté API : jamais écrite autrement que par le générateur (traçabilité).
 */
#[ORM\Entity]
#[ORM\Table(name: 'facturation_serie_numerotation')]
#[ORM\UniqueConstraint(name: 'uniq_facturation_serie', columns: ['profil_exploitant_id', 'periode_id', 'prefixe'])]
#[ApiResource(
    shortName: 'SerieNumerotationFacturation',
    operations: [
        new GetCollection(
            uriTemplate: '/series-numerotation',
            security: "is_granted('PERM', 'facturation.lire')",
        ),
        new Get(
            uriTemplate: '/series-numerotation/{id}',
            security: "is_granted('PERM', 'facturation.lire')",
        ),
    ],
    normalizationContext: ['groups' => ['serie:read']],
)]
class SerieNumerotation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['serie:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['serie:read'])]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\ManyToOne(targetEntity: PeriodeComptable::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['serie:read'])]
    private ?PeriodeComptable $periode = null;

    #[ORM\Column(length: 4, enumType: PrefixeSerie::class)]
    #[Groups(['serie:read'])]
    private PrefixeSerie $prefixe = PrefixeSerie::Facture;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['serie:read'])]
    private int $dernierNumero = 0;

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

    public function getPeriode(): ?PeriodeComptable
    {
        return $this->periode;
    }

    public function setPeriode(?PeriodeComptable $periode): self
    {
        $this->periode = $periode;

        return $this;
    }

    public function getPrefixe(): PrefixeSerie
    {
        return $this->prefixe;
    }

    public function setPrefixe(PrefixeSerie $prefixe): self
    {
        $this->prefixe = $prefixe;

        return $this;
    }

    public function getDernierNumero(): int
    {
        return $this->dernierNumero;
    }

    public function setDernierNumero(int $dernierNumero): self
    {
        $this->dernierNumero = $dernierNumero;

        return $this;
    }

    /** Incrémente et renvoie la nouvelle valeur — appelé **uniquement** sous verrou pessimiste. */
    public function incrementer(): int
    {
        return ++$this->dernierNumero;
    }
}
