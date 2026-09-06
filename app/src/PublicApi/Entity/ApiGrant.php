<?php

declare(strict_types=1);

namespace App\PublicApi\Entity;

use App\Organisation\Entity\Etablissement;
use App\PublicApi\Enum\ApiScope;
use App\PublicApi\Enum\GrantStatus;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le consentement d'un etablissement a une application tierce, sur des portees nommees.
 *
 * ⚠ **C'EST ICI, ET NULLE PART AILLEURS, QUE LE CLOISONNEMENT DE L'API PUBLIQUE SE JOUE.** Une cle
 * dit « je suis l'application X » ; elle ne dit pas a quelles donnees X a droit. Si on faisait
 * porter l'acces par la cle, une cle delivree une fois vaudrait pour tous les etablissements du
 * reseau — et rien ne le signalerait, puisque la requete serait parfaitement authentifiee.
 *
 * ⚠ **UN CONSENTEMENT SE RETIRE, ET LE RETRAIT EST ABSORBANT** ({@see GrantStatus}). Reaccorder est
 * un geste explicite qui cree une ligne neuve : le retrait reste dans l'historique, et un partenaire
 * ne peut pas le defaire en redemandant.
 *
 * Les portees sont stockees en JSON plutot qu'en table de liaison : elles sont peu nombreuses, lues
 * en bloc a chaque requete, et jamais interrogees separement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'public_api_grant')]
#[ORM\Index(columns: ['application_id'], name: 'idx_public_api_grant_application')]
#[ORM\Index(columns: ['etablissement_id'], name: 'idx_public_api_grant_etablissement')]
class ApiGrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PartnerApplication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?PartnerApplication $application = null;

    // CASCADE : un etablissement supprime emporte les consentements qu'il avait donnes — ils
    // n'ont plus d'objet, et les laisser ferait pointer un acces vers rien.
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Etablissement $etablissement = null;

    /** @var list<string> les valeurs de {@see ApiScope} */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    #[ORM\Column(length: 12, enumType: GrantStatus::class, options: ['default' => 'active'])]
    private GrantStatus $status = GrantStatus::Active;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $grantedAt;

    // SET NULL : qui a accorde reste une information utile, mais le depart d'un salarie ne doit
    // pas retirer un acces que l'etablissement a consenti.
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $grantedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getApplication(): ?PartnerApplication
    {
        return $this->application;
    }

    public function setApplication(?PartnerApplication $application): self
    {
        $this->application = $application;

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

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param list<ApiScope> $scopes */
    public function setScopes(array $scopes): self
    {
        $this->scopes = array_values(array_map(static fn (ApiScope $s): string => $s->value, $scopes));

        return $this;
    }

    public function allows(ApiScope $scope): bool
    {
        return GrantStatus::Active === $this->status && \in_array($scope->value, $this->scopes, true);
    }

    public function getStatus(): GrantStatus
    {
        return $this->status;
    }

    public function setStatus(GrantStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getGrantedBy(): ?Utilisateur
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(?Utilisateur $grantedBy): self
    {
        $this->grantedBy = $grantedBy;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }
}
