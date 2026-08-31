<?php

declare(strict_types=1);

namespace App\Acces\Port;

use App\Acces\Enum\CredentialEncoding;
use App\Acces\Enum\DecisionPoint;
use App\Acces\Enum\PassageReporting;
use App\Acces\Enum\RevocationCapability;

/**
 * Ce qu'un pilote d'accès sait réellement faire (D17). Déclaration, pas configuration : elle est
 * portée par l'adaptateur lui-même, qui est le seul à connaître la vérité de son matériel.
 *
 * **Le défaut que cette classe existe pour corriger.** `PiloteAcces` expose quatre opérations et
 * suppose que tout pilote sait les quatre. C'est vrai de la topologie que nous connaissons, faux de
 * toutes les autres — une serrure autonome n'a pas de battement de cœur et ne peut recevoir aucune
 * liste de révocation. Sans déclaration, la plateforme appelle, l'adaptateur ne fait rien, et
 * **personne ne l'apprend** : on croit avoir révoqué un accès, la porte s'ouvre quand même, et la
 * découverte se fait sur incident. C'est le pire mode de défaillance de ce domaine.
 *
 * Conséquence à respecter partout : une opération non déclarée **échoue explicitement** (ACC-1), elle
 * n'est jamais ignorée en silence. Et l'exploitant doit voir dans l'interface que ce site-là ne sait
 * pas révoquer immédiatement — c'est une promesse commerciale, pas un détail technique.
 *
 * Identifiants en anglais (D5), comme `TypeDroitAcces::Booking` : le module `Acces` est historique et
 * francophone, mais les ajouts suivent la règle en vigueur plutôt que d'épaissir la dette.
 */
final readonly class AccessDriverCapabilities
{
    public function __construct(
        public DecisionPoint $decisionPoint,
        public RevocationCapability $revocation,
        public CredentialEncoding $encoding,
        public PassageReporting $passageReporting,
    ) {
    }

    /**
     * Déclaration d'un pilote dont le protocole n'est pas cadré : **on ne promet rien**.
     *
     * C'est le cas d'`ItboxAdapter` et de `SmartAccessAdapter`, squelettes qui lèvent une exception
     * sur les quatre opérations en attendant une spécification fournisseur (E-4 au registre des
     * bloqueurs externes, D19). Déclarer au plus pessimiste est ici la seule honnêteté possible :
     * supposer des capacités qu'on n'a pas vérifiées reproduirait exactement le défaut qu'on corrige.
     */
    public static function unspecified(): self
    {
        return new self(
            DecisionPoint::Controller,
            RevocationCapability::Impossible,
            CredentialEncoding::None,
            PassageReporting::None,
        );
    }

    /** La plateforme peut-elle promettre à l'exploitant qu'une révocation prend effet tout de suite ? */
    public function revokesImmediately(): bool
    {
        return $this->revocation === RevocationCapability::Immediate;
    }

    /** Une liste de révocation a-t-elle un sens pour ce pilote (immédiate ou différée) ? */
    public function acceptsRevocationList(): bool
    {
        return $this->revocation !== RevocationCapability::Impossible;
    }

    /** Ce pilote sait-il écrire une autorisation sur un médium (distinct de l'appairage) ? */
    public function encodes(): bool
    {
        return $this->encoding === CredentialEncoding::Writes;
    }

    /** Le battement de cœur n'a de sens que si un équipement raccordé décide ou rend compte. */
    public function reportsState(): bool
    {
        return $this->passageReporting !== PassageReporting::None;
    }
}
