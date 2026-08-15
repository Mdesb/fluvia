<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\TraitementSoldeResiduel;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Paramétrage PMV par établissement (US-L5-06/07) : recharge d'un PMV expiré autorisée ou non,
 * règle de calcul de l'échéance, traitement du solde résiduel à expiration. Portée **Établissement**
 * (contrairement au reste de M4, cf. §1 plan-crm.md).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_parametre_pmv_etablissement')]
#[ORM\UniqueConstraint(name: 'uniq_parametre_pmv_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'ParametrePmvEtablissement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.parametrer') or is_granted('PERM', 'crm.lire')"),
        new Get(security: "is_granted('PERM', 'crm.parametrer') or is_granted('PERM', 'crm.lire')"),
        new Post(security: "is_granted('PERM', 'crm.parametrer')"),
        new Patch(security: "is_granted('PERM', 'crm.parametrer')"),
    ],
    normalizationContext: ['groups' => ['parametre:read']],
    denormalizationContext: ['groups' => ['parametre:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact'])]
class ParametrePmvEtablissement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['parametre:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['parametre:read', 'parametre:write'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['parametre:read', 'parametre:write'])]
    private bool $rechargeExpireeAutorisee = false;

    /** @var array{mode: string, valeur: int} {mode: duree_jours|jour_fixe, valeur} */
    #[ORM\Column]
    #[Groups(['parametre:read', 'parametre:write'])]
    private array $regleEcheance = ['mode' => 'duree_jours', 'valeur' => 365];

    #[ORM\Column(length: 24, enumType: TraitementSoldeResiduel::class, options: ['default' => 'conserve'])]
    #[Groups(['parametre:read', 'parametre:write'])]
    private TraitementSoldeResiduel $traitementSoldeResiduel = TraitementSoldeResiduel::Conserve;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function isRechargeExpireeAutorisee(): bool
    {
        return $this->rechargeExpireeAutorisee;
    }

    public function setRechargeExpireeAutorisee(bool $rechargeExpireeAutorisee): self
    {
        $this->rechargeExpireeAutorisee = $rechargeExpireeAutorisee;

        return $this;
    }

    /** @return array{mode: string, valeur: int} */
    public function getRegleEcheance(): array
    {
        return $this->regleEcheance;
    }

    /** @param array{mode: string, valeur: int} $regleEcheance */
    public function setRegleEcheance(array $regleEcheance): self
    {
        $this->regleEcheance = $regleEcheance;

        return $this;
    }

    public function getTraitementSoldeResiduel(): TraitementSoldeResiduel
    {
        return $this->traitementSoldeResiduel;
    }

    public function setTraitementSoldeResiduel(TraitementSoldeResiduel $traitementSoldeResiduel): self
    {
        $this->traitementSoldeResiduel = $traitementSoldeResiduel;

        return $this;
    }

    /**
     * Calcule la nouvelle échéance à partir de la règle paramétrée (US-L5-04/06). Mode `duree_jours` :
     * échéance = date de recharge + N jours. Mode `jour_fixe` : prochaine occurrence du N-ième jour de
     * décembre de l'année en cours (ou suivante si déjà dépassé) — simplification volontaire (le
     * cahier ne détaille pas ce mode, ⚠ HYPOTHÈSE).
     */
    public function calculerEcheance(\DateTimeImmutable $depuis = new \DateTimeImmutable()): \DateTimeImmutable
    {
        if ($this->regleEcheance['mode'] === 'jour_fixe') {
            $jour = max(1, min(28, (int) $this->regleEcheance['valeur']));
            $candidate = $depuis->setDate((int) $depuis->format('Y'), 12, $jour)->setTime(0, 0);
            if ($candidate <= $depuis) {
                $candidate = $candidate->modify('+1 year');
            }

            return $candidate;
        }

        $jours = (int) $this->regleEcheance['valeur'];

        return $depuis->modify(sprintf('+%d days', max(1, $jours)));
    }
}
