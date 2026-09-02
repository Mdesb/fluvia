<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Facturation\Entity\Facture;

/**
 * D'OÙ VIENNENT LES TROIS MENTIONS OBLIGATOIRES DU PROFIL FRANÇAIS.
 *
 * ── ⚠ POURQUOI UNE INTERFACE POUR UNE SEULE IMPLÉMENTATION ─────────────────────────────────────
 *
 * `CiiSerializer` est une fonction du document : on lui donne une `Facture`, il rend du XML. Ses
 * tests assemblent une facture en mémoire et vérifient des chemins XPath, sans base.
 *
 * Le fournisseur réel, lui, lit `ParametreFacturationEtablissement` par Doctrine. Le brancher en dur
 * obligerait chaque test du sérialiseur à monter un conteneur pour prouver une concaténation XML —
 * et l'outil qui fabrique le témoin soumis aux validateurs ne pourrait pas s'en servir du tout.
 *
 * L'interface n'est donc pas de l'abstraction gratuite : c'est ce qui permet de PROUVER que les
 * notes sortent, sans base de données.
 */
interface InvoiceMentions
{
    /**
     * Le texte de chaque mention, par code sujet — `PMT`, `PMD`, `AAB`.
     *
     * ⚠ Une valeur `null` ou vide veut dire « pas rédigée », et le sérialiseur n'écrit alors AUCUNE
     * note : une note vide satisferait la présence de la balise et affirmerait au client des
     * conditions blanches. L'absence, elle, se voit dans le rapport de validation.
     *
     * @return array<string, string|null>
     */
    public function pour(Facture $facture): array;
}
