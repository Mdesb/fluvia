<?php

declare(strict_types=1);

namespace App\Platform\Event;

use App\Platform\Event\Exception\InvalidDomainEventException;

/**
 * Nom d'événement du catalogue — `domain.fact_past_tense` (RG-PLAT-02, D5).
 *
 * Objet valeur volontairement strict : le nom est la **clé d'abonnement** du bus (voir
 * {@see SymfonyEventBus}). Un module s'abonne à la chaîne `invoice.overdue`, pas à une classe PHP
 * d'un autre module — c'est ce qui réalise le découplage exigé par D2. Une faute de frappe dans un
 * nom ne provoquerait donc aucune erreur : l'abonné ne serait simplement jamais appelé, en silence.
 * D'où la validation à la construction, seul endroit où l'on peut encore échouer bruyamment.
 *
 * La conformité au catalogue (`CONTRACT/catalogue-evenements.md`) est vérifiée séparément, au niveau
 * du manifeste de module (RG-PLAT-06) : ici on ne valide que la **forme**, pas l'appartenance.
 */
final class EventName implements \Stringable
{
    /**
     * `domain.fact_past_tense` — minuscules, `snake_case`, deux segments exactement.
     *
     * Publique et unique : `ManifestCatalogueTest` s'en sert pour valider le catalogue lui-même. Une
     * seconde copie de cette expression quelque part serait la garantie qu'un jour les deux divergent,
     * et que le catalogue déclarerait des noms que le code refuse.
     */
    public const PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    public readonly string $value;

    public function __construct(string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidDomainEventException(sprintf(
                'Nom d\'événement invalide : "%s". Attendu « domain.fact_past_tense » en anglais, '
                .'minuscules et snake_case (RG-PLAT-02) — par exemple « supplier_invoice.recorded ».',
                $value,
            ));
        }

        $this->value = $value;
    }

    /** Le domaine émetteur (`supplier_invoice` dans `supplier_invoice.recorded`). */
    public function domain(): string
    {
        return substr($this->value, 0, (int) strpos($this->value, '.'));
    }

    /** Le fait, au passé (`recorded` dans `supplier_invoice.recorded`). */
    public function fact(): string
    {
        return substr($this->value, (int) strpos($this->value, '.') + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
