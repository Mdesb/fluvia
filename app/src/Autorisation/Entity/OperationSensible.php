<?php

declare(strict_types=1);

namespace App\Autorisation\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Catalogue paramétrable des opérations sensibles (RG-AUTZ-01) : chaque entrée est identifiée par
 * une clé stable (ex. `vente.annuler`), référence la permission `module.action` binaire qui la
 * protège déjà (référence logique, pas de FK stricte — reste paramétrable indépendamment de l'ordre
 * de création des `Permission`), et peut être désactivée sans suppression.
 *
 * ⚠ Dérogation mineure et assumée à la règle constitution §3 « id = UUID » (§0 n°1 du plan) : `code`
 * est une clé métier stable (jamais un compteur), littéralement exigée par RG-AUTZ-01/§5 spec.
 */
#[ORM\Entity]
#[ORM\Table(name: 'atz_operation_sensible')]
#[ApiResource(
    shortName: 'OperationSensible',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'autorisation.lire') or is_granted('PERM', 'autorisation.gerer')"),
        // `code` contient un point (ex. « vente.annuler ») : requirement explicite `.+` pour que le
        // routeur ne l'interprète pas comme un suffixe `{_format}` (sinon « vente.annuler » serait
        // scindé en id=« vente » / format=« annuler »).
        new Get(
            uriTemplate: '/operation_sensibles/{code}',
            requirements: ['code' => '.+'],
            security: "is_granted('PERM', 'autorisation.lire') or is_granted('PERM', 'autorisation.gerer')",
        ),
        new Post(security: "is_granted('PERM', 'autorisation.gerer')"),
        new Patch(
            uriTemplate: '/operation_sensibles/{code}',
            requirements: ['code' => '.+'],
            security: "is_granted('PERM', 'autorisation.gerer')",
        ),
    ],
    normalizationContext: ['groups' => ['operation_sensible:read']],
    denormalizationContext: ['groups' => ['operation_sensible:write']],
)]
#[ApiFilter(BooleanFilter::class, properties: ['active'])]
class OperationSensible
{
    public const MOTIF_MODULE_ACTION = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    #[ORM\Id]
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Groups(['operation_sensible:read', 'operation_sensible:write', 'limite:read', 'escalade:read'])]
    private string $code = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Groups(['operation_sensible:read', 'operation_sensible:write', 'limite:read', 'escalade:read'])]
    private string $libelle = '';

    /** Référence logique vers `Permission.getCode()` — pas de FK stricte (RG-AUTZ-01). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: self::MOTIF_MODULE_ACTION, message: 'Le format attendu est « module.action » (ex. « vente.annuler »).')]
    #[Groups(['operation_sensible:read', 'operation_sensible:write'])]
    private string $moduleAction = '';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['operation_sensible:read', 'operation_sensible:write'])]
    private bool $active = true;

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getModuleAction(): string
    {
        return $this->moduleAction;
    }

    public function setModuleAction(string $moduleAction): self
    {
        $this->moduleAction = $moduleAction;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }
}
