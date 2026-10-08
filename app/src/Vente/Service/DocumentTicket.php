<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\Vente;

/**
 * LE TICKET, EN UN SEUL ENDROIT — ce qui part sur le papier, et rien d'autre.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ────────────────────────────────────────────────────────────────
 *
 * `TicketProcessor` fabriquait ce document dans sa propre méthode. Le rendu PDF du lot 9 (D124) en
 * aura besoin du même : le construire une seconde fois créerait deux vérités sur le même papier. C'est
 * précisément ce que l'en-tête de `TicketProcessor` raconte déjà pour la règle d'impression — écrite
 * deux fois, corrigée une seule le 29/08, divergente le jour même. **Une règle recopiée diverge au
 * PREMIER correctif, pas au dixième.**
 *
 * ── ⚠ CE QUI SÉPARE LE DOCUMENT DE LA RÉPONSE ──────────────────────────────────────────────────
 *
 * Ici : ce qu'un client lit sur son papier — numéro, date, lignes, totaux, la mention DUPLICATA.
 * Pas ici : `mode`, `renvoiPropose`, `renvoye`, `canal`, `impressionAutomatique`, `venteGratuite`.
 * Ceux-là décrivent l'INTERACTION avec l'écran de caisse, pas le document. Un papier n'a rien à
 * faire de savoir qu'un renvoi par SMS était proposé.
 *
 * Jamais ici, quel que soit le lot : le code d'accès du billet (D124 — un duplicata deviendrait un
 * second billet), ni une mention d'avoir ou de correction (D125 — l'avoir a son propre justificatif,
 * le duplicata reproduit l'original). `TicketDocumentTest` tient les deux.
 *
 * Viendront plus tard : le taux gravé par ligne et la ventilation TVA (D123, lots 6 et 7, un seul
 * calcul partagé avec les écritures) ; le vendeur figé à la validation, jamais lu en direct du profil
 * de l'exploitant, et le document construit depuis l'empreinte scellée (D124, lot 7).
 *
 * ── ⚠ `duplicata` SE REÇOIT, IL NE SE RECALCULE PAS ────────────────────────────────────────────
 *
 * C'est le seul champ dangereux du lot. La mention est LUE de la vente au moment de l'édition
 * (`$vente->isImprime()`), et la vente est marquée imprimée dans la foulée. Une classe qui la
 * relirait elle-même la lirait donc APRÈS coup, et rendrait « DUPLICATA » sur tout — y compris sur
 * l'original. Elle est donc passée en argument, par l'appelant qui l'a lue au bon instant.
 *
 * `TicketDocumentTest` le prouve sous le seuil d'impression : le premier ticket est l'original. Au
 * lot 9, D124 fera venir la mention du journal des éditions, et non plus d'`imprime`.
 *
 * @phpstan-type LigneTicket array{id: string, libelle: mixed, tarif: ?string, quantite: int,
 *     prixUnitaire: ?string, impactOptionsUnitaire: ?string, remiseLigne: ?string,
 *     remiseType: ?string, montantLigne: ?string, optionsSelectionnees: mixed,
 *     promotionsAppliquees: mixed}
 * @phpstan-type Ticket array{vente: string, numero: ?string, date: string, lignes: list<LigneTicket>,
 *     total: ?string, totalRemises: ?string, duplicata: bool}
 */
final class DocumentTicket
{
    /**
     * @param bool $duplicata lu par l'appelant AVANT de marquer la vente imprimée — voir l'en-tête
     *
     * @return array{vente: string, numero: ?string, date: string, lignes: list<array<string, mixed>>,
     *     total: ?string, totalRemises: ?string, duplicata: bool}
     */
    public function pour(Vente $vente, bool $duplicata): array
    {
        return [
            'vente' => (string) $vente->getId(),
            'numero' => $vente->getNumero(),
            'date' => $vente->getDate()->format(\DATE_ATOM),
            'lignes' => $this->lignes($vente),
            'total' => $vente->getTotal(),
            'totalRemises' => $vente->getTotalRemises(),
            'duplicata' => $duplicata,
        ];
    }

    /**
     * Le contenu du ticket, **relu de la vente et de rien d'autre**.
     *
     * Les libellés viennent de la ligne, pas du catalogue : c'est ce qui rend un duplicata fidèle six
     * mois plus tard, quand le produit a changé de nom ou n'existe plus. Voir
     * `LigneVente::$libelleProduit`.
     *
     * ⚠ `libelle` est un tableau traduisible (`['fr' => '…']`), pas une chaîne, et `remiseLigne` vaut
     * `null` sans remise, pas `'0.00'`. Les deux comptent pour un rendu papier : le premier
     * imprimerait « Array », le second confondrait « aucune remise » avec « une remise de zéro ».
     *
     * @return list<array<string, mixed>>
     */
    private function lignes(Vente $vente): array
    {
        $lignes = [];
        foreach ($vente->getLignes() as $ligne) {
            $lignes[] = [
                'id' => (string) $ligne->getId(),
                'libelle' => $ligne->getLibelleProduit(),
                'tarif' => $ligne->getLibelleTypeTarif(),
                'quantite' => $ligne->getQuantite(),
                'prixUnitaire' => $ligne->getPrixUnitaire(),
                'impactOptionsUnitaire' => $ligne->getImpactOptionsUnitaire(),
                'remiseLigne' => $ligne->getRemiseLigne(),
                'remiseType' => $ligne->getRemiseType()?->value,
                'montantLigne' => $ligne->getMontantLigne(),
                'optionsSelectionnees' => $ligne->getOptionsSelectionnees(),
                'promotionsAppliquees' => $ligne->getPromotionsAppliquees(),
            ];
        }

        return $lignes;
    }
}
