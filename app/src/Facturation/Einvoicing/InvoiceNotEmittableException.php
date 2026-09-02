<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Facturation\Entity\Facture;

/**
 * LE REFUS D'ÉMETTRE, AVEC SA RAISON DÉTAILLÉE.
 *
 * ⚠ POURQUOI UNE EXCEPTION PLUTÔT QU'UN FICHIER INCOMPLET. Un XML bien formé dont
 * `<ram:Name/>` est vide **passe le schéma** et se fait refuser des semaines plus tard par la
 * plateforme destinataire, avec un code d'erreur que personne ne rattache à la saisie manquante.
 * Entre-temps, l'exploitant croit avoir facturé.
 *
 * Un fichier absent se voit tout de suite ; un fichier vide se voit dans un mois.
 *
 * ⚠ ET LE MESSAGE NOMME CHAQUE TERME AVEC SON EMPLACEMENT. « Facture incomplète » enverrait
 * chercher partout. `InvoiceReadiness` sait déjà où chaque valeur devrait vivre — on transporte
 * cette information jusqu'à l'appelant au lieu de la résumer.
 */
final class InvoiceNotEmittableException extends \RuntimeException
{
    /** @param list<array{terme: BusinessTerm, ou: string}> $manques */
    private function __construct(
        string $message,
        public readonly array $manques,
    ) {
        parent::__construct($message);
    }

    /** @param list<array{terme: BusinessTerm, ou: string}> $manques */
    public static function pour(Facture $facture, array $manques): self
    {
        $lignes = array_map(
            static fn (array $m): string => sprintf(
                '  %s  %s — %s',
                $m['terme']->value,
                $m['terme']->libelle(),
                $m['ou'],
            ),
            $manques,
        );

        return new self(
            sprintf(
                "La facture %s ne peut pas être émise au format européen : %d terme(s) obligatoire(s) manquent.\n%s",
                $facture->getNumero() ?? '(sans numéro)',
                count($manques),
                implode("\n", $lignes),
            ),
            $manques,
        );
    }
}
