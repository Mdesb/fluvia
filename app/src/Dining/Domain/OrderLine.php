<?php

declare(strict_types=1);

namespace App\Dining\Domain;

/**
 * Une ligne de commande à table (ACT-4, D16).
 *
 * **L'envoi en cuisine est le seul moment irréversible du module**, et toutes les règles qui suivent
 * en découlent. Avant l'envoi, la ligne n'existe qu'à l'écran du serveur : on la corrige, on l'efface,
 * personne n'en saura rien. Après l'envoi, un cuisinier a saisi une poêle — le produit est sorti du
 * stock, qu'on le serve ou qu'on le jette.
 *
 * C'est le même partage que celui posé sur le stock : **on refuse ce qui prétend défaire un fait, on
 * n'empêche jamais de le constater.** Une ligne envoyée ne se retire pas, elle s'annule ; et une
 * annulation après envoi est une perte, pas une gomme.
 */
final class OrderLine
{
    private LineStatus $status = LineStatus::Draft;
    private ?\DateTimeImmutable $firedAt = null;
    private ?string $voidReason = null;

    public function __construct(
        public readonly CourseRef $course,
        public readonly string $label,
        public readonly int $quantity,
        public readonly string $unitAmount,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Une ligne de commande porte au moins une unité.');
        }
    }

    public function status(): LineStatus
    {
        return $this->status;
    }

    public function firedAt(): ?\DateTimeImmutable
    {
        return $this->firedAt;
    }

    public function voidReason(): ?string
    {
        return $this->voidReason;
    }

    /**
     * Envoi en cuisine.
     *
     * **Refuse le second envoi**, et c'est la règle la plus concrète du module : un service envoyé
     * deux fois, ce sont deux fois les plats qui sortent. Le cuisinier ne peut pas savoir que le
     * second bon est un doublon — il voit une commande, il la prépare.
     */
    public function fire(\DateTimeImmutable $at): self
    {
        if (LineStatus::Draft !== $this->status) {
            throw new \LogicException(sprintf(
                'La ligne « %s » a déjà quitté le brouillon (état : %s) : la renvoyer ferait sortir les plats deux fois.',
                $this->label,
                $this->status->value,
            ));
        }

        $this->status = LineStatus::Fired;
        $this->firedAt = $at;

        return $this;
    }

    public function serve(): self
    {
        if (LineStatus::Fired !== $this->status) {
            throw new \LogicException(sprintf(
                'Seule une ligne envoyée en cuisine peut être servie (état : %s).',
                $this->status->value,
            ));
        }

        $this->status = LineStatus::Served;

        return $this;
    }

    /** Une ligne encore au brouillon s'efface sans laisser de trace : rien n'a été engagé. */
    public function isRemovable(): bool
    {
        return LineStatus::Draft === $this->status;
    }

    /**
     * Annulation après envoi. **Exige un motif**, et la ligne reste consommée.
     *
     * Le motif n'est pas une formalité : c'est la seule chose qui distingue une erreur de saisie d'un
     * plat renvoyé par le client, et ces deux-là ne se traitent pas pareil au moment de comprendre une
     * perte en fin de mois.
     */
    public function void(string $reason): self
    {
        if (LineStatus::Draft === $this->status) {
            throw new \LogicException(
                'Une ligne au brouillon se retire, elle ne s\'annule pas : rien n\'a encore été engagé.',
            );
        }
        if (LineStatus::Voided === $this->status) {
            throw new \LogicException('Cette ligne est déjà annulée.');
        }
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException(
                'Une annulation après envoi exige un motif : c\'est lui qui distingue l\'erreur de saisie du plat renvoyé.',
            );
        }

        $this->status = LineStatus::Voided;
        $this->voidReason = trim($reason);

        return $this;
    }

    /** Compte-t-elle dans l'addition du client ? */
    public function isBillable(): bool
    {
        return LineStatus::Voided !== $this->status;
    }

    /**
     * A-t-elle consommé de la matière ?
     *
     * Vrai dès l'envoi, **y compris après annulation** : c'est ce qui empêche une addition corrigée
     * de faire disparaître une sortie de stock bien réelle.
     */
    public function hasConsumed(): bool
    {
        return LineStatus::Draft !== $this->status;
    }
}
