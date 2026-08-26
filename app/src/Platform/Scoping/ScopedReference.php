<?php

declare(strict_types=1);

namespace App\Platform\Scoping;

use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Socle partagé **plus** ajout local, pour un référentiel dont la liste n'est pas décidée par nous
 * seuls (D51).
 *
 * **Ce que Maxime a demandé, et qui n'en fait qu'un** : *« un seul produit de créé, et derrière que ce
 * soit juste la tarification qui change »*, et *« le paramétrage entièrement modifiable par
 * l'utilisateur »*. Un exploitant doit pouvoir ajouter son propre type de tarif ou sa propre catégorie
 * **sans qu'on lui livre une version**, et sans que son ajout apparaisse chez le voisin.
 *
 * **Deux exigences, arbitrées, et elles ne sont pas symétriques :**
 * - le socle n'est écrivable que par la plateforme — sinon « partagé » signifie « modifiable par tout
 *   le monde », et un établissement qui renomme une ligne du socle change le référentiel de tous les
 *   autres, **silencieusement et immédiatement** (D41) ;
 * - une lecture voit le socle **plus** ses propres ajouts, jamais ceux d'un autre.
 *
 * **Le piège de la seconde exigence, et la raison du test qui l'accompagne.** Un filtre écrit
 * naïvement — `etablissement = :courant` — ne rend pas « un peu moins de lignes » : il fait
 * **disparaître tout le socle**, donc tous les tarifs de base, donc tous les prix. Le catalogue est
 * vide au guichet. C'est D39 dans sa forme la plus coûteuse : qui rejoue une règle de portée la rejoue
 * **entière**, ou ne filtre pas du tout.
 *
 * L'usage : `use ScopedReference;` dans l'entité, et `ScopedReferenceQuery::restreindre()` dans son
 * extension Doctrine. Les deux vont ensemble — le trait seul rend la donnée lisible par tous.
 */
trait ScopedReference
{
    /**
     * `socle` par défaut : une ligne créée sans que personne ne se pose la question **n'appartient à
     * aucun établissement**, et le défaut le dit au lieu de le laisser deviner par un `null`.
     */
    #[ORM\Column(length: 8, enumType: ReferenceScope::class, options: ['default' => 'socle'])]
    #[Groups(['ref:read', 'cat:read', 'qf:read'])]
    private ReferenceScope $portee = ReferenceScope::Base;

    /** Renseigné pour un ajout local, nul sur le socle. */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['ref:read', 'cat:read', 'qf:read'])]
    private ?Etablissement $etablissement = null;

    public function getPortee(): ReferenceScope
    {
        return $this->portee;
    }

    public function estDuSocle(): bool
    {
        return $this->portee === ReferenceScope::Base;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    /**
     * Rattache la ligne à un établissement, **et la déclare locale du même geste**.
     *
     * Les deux champs ne se posent jamais séparément : une ligne rattachée qui serait restée « socle »
     * fuirait chez tous les autres, et c'est exactement l'oubli qu'un `null = socle` aurait rendu
     * possible. Le seul chemin qui existe les pose ensemble.
     */
    public function rattacherA(Etablissement $etablissement): static
    {
        $this->etablissement = $etablissement;
        $this->portee = ReferenceScope::Local;

        return $this;
    }
}
