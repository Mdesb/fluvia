<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
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
#[ORM\UniqueConstraint(name: 'uniq_facturation_serie', columns: ['profil_exploitant_id', 'exercice', 'prefixe'])]
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

    /**
     * L'ANNEE DE LA SEQUENCE — PAS LA PERIODE COMPTABLE.
     *
     * ⚠ CETTE COLONNE REMPLACE `periode`, ET LE DEFAUT QU'ELLE CORRIGE ETAIT BLOQUANT.
     * `PeriodeComptableResolver` cree une periode par MOIS. Le compteur etait donc mensuel, alors
     * que `GenerateurNumeroFacture` compose un numero qui ne porte que l'ANNEE — `FA-2026-00001` —
     * et que `uniq_facture_numero` est unique sur ce numero seul. Resultat : la premiere facture de
     * chaque nouveau mois reprenait le numero de la premiere du mois precedent, et la contrainte la
     * rejetait. Mesure du 14/09/2026 sur la preproduction : aucune facture de septembre ne pouvait
     * etre emise, celle d'aout portant deja `FA-2026-00001`.
     *
     * Le contrat etait pourtant deja ecrit ailleurs : le docbloc de `ScellementFactureHandler`
     * decrit la serie comme portee par « (profilExploitant, exercice, prefixe) ». C'est
     * l'implementation qui s'en ecartait, pas la specification.
     *
     * ⚠ A NE PAS CONFONDRE AVEC LA CHAINE NF525. `Facture::numeroSequence` est une SECONDE sequence,
     * continue par exploitant et sans remise a zero annuelle, calculee par `ScellementFactureHandler`
     * a partir du dernier maillon. Elle ne passe pas par cette table et n'est pas touchee ici.
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    #[Groups(['serie:read'])]
    private int $exercice = 0;

    #[ORM\Column(length: 4, enumType: PrefixeSerie::class)]
    #[Groups(['serie:read'])]
    private PrefixeSerie $prefixe = PrefixeSerie::Facture;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['serie:read'])]
    private int $dernierNumero = 0;

    /**
     * LE CODE QUI SEPARE LES SERIES DE DEUX PROFILS D'UN MEME SIREN (`FA-<code>-2026-00001`).
     *
     * `null` : la serie sans code, celle du premier profil du SIREN a avoir numerote. Pose a la
     * creation de la premiere serie du profil par `GenerateurNumeroFacture`, recopie ensuite sur ses
     * series suivantes : il ne change jamais.
     */
    #[ORM\Column(length: 8, nullable: true)]
    #[Groups(['serie:read'])]
    private ?string $code = null;

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

    public function getExercice(): int
    {
        return $this->exercice;
    }

    public function setExercice(int $exercice): self
    {
        $this->exercice = $exercice;

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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    /** Incrémente et renvoie la nouvelle valeur — appelé **uniquement** sous verrou pessimiste. */
    public function incrementer(): int
    {
        return ++$this->dernierNumero;
    }
}
