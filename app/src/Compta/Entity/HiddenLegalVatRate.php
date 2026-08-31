<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * « UN ŒIL POUR MASQUER LE TAUX » — l'exploitant range le référentiel sans le mutiler.
 *
 * Demandé par Maxime : « dans le cas où un client n'a jamais besoin d'un taux, on devrait avoir un
 * œil pour masquer le taux ».
 *
 * ── CE N'EST PAS UNE COMMODITÉ D'ERGONOMIE, C'EST CE QUI REND LE RÉFÉRENTIEL UTILISABLE ─────────
 *
 * Le référentiel européen complet fait des centaines d'entrées ; une piscine municipale française en
 * emploie trois. Sans masquage, on aurait remplacé « il saisit ses trois taux à la main » par « il
 * cherche ses trois taux dans deux cents » — et le second est PIRE, parce qu'il a l'air complet.
 * L'argument chiffré est venu d'une mesure : 31 taux existaient déjà en base pour trois
 * établissements de démonstration, chacun retapant les siens. Masquer est ce qui empêche 31 de
 * devenir 300.
 *
 * ── ON MASQUE, ON NE SUPPRIME PAS, ET LA DIFFÉRENCE EST TOUT ────────────────────────────────────
 *
 * Le masquage vit ICI, sur une table à part, et ne touche jamais `LegalVatRate`. Deux raisons :
 *
 *   — le référentiel dit ce que la loi dit, et la loi ne change pas parce qu'un exploitant n'en a
 *     pas l'usage. L'entrée hongroise existe toujours pour celui qui en a besoin ;
 *   — c'est réversible par construction. Supprimer la ligne de masquage rouvre le taux. Un `Delete`
 *     sur cette table est donc un geste anodin — c'est le contraire d'une suppression de donnée,
 *     c'est l'annulation d'une préférence.
 *
 * ⚠ CETTE ENTITE N'EST PAS EXPOSEE NON PLUS, et pour une raison de forme : le referentiel
 * n'etant pas une ressource, on ne peut pas designer un taux par son IRI. Masquer et demasquer
 * passent donc par la vue `VatRateCatalog`, qui accepte un identifiant. C'est aussi plus juste :
 * l'ecran manipule un catalogue, pas des lignes de preference.
 *
 * ⚠ Masquer n'invalide RIEN. Si un taux masqué est déjà employé par une facture ou une écriture, il
 * continue de s'appliquer : le masque porte sur ce qu'on PROPOSE, jamais sur ce qui a déjà servi.
 * Confondre les deux ferait disparaître de l'historique des lignes parfaitement valides — et ce
 * serait la forme la plus coûteuse du défaut, puisqu'elle passerait pour du rangement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounting_hidden_legal_vat_rate')]
#[ORM\UniqueConstraint(name: 'uniq_hidden_legal_vat_rate', columns: ['profil_exploitant_id', 'legal_vat_rate_id'])]
class HiddenLegalVatRate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * ⚠ Posé par le serveur d'après le contexte, jamais par le client (D41).
     *
     * Sans quoi masquer un taux chez le voisin tiendrait en une requête — et le voisin ne le verrait
     * pas, puisque le symptôme est une absence dans une liste.
     */
    #[ORM\ManyToOne(targetEntity: ProfilExploitant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?ProfilExploitant $profilExploitant = null;

    #[ORM\ManyToOne(targetEntity: LegalVatRate::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?LegalVatRate $legalVatRate = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $hiddenAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->hiddenAt = new \DateTimeImmutable();
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

    public function getLegalVatRate(): ?LegalVatRate
    {
        return $this->legalVatRate;
    }

    public function setLegalVatRate(?LegalVatRate $legalVatRate): self
    {
        $this->legalVatRate = $legalVatRate;

        return $this;
    }

    public function getHiddenAt(): \DateTimeImmutable
    {
        return $this->hiddenAt;
    }
}
