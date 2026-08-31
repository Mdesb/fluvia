<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\Vente;

/**
 * FAUT-IL SORTIR UN TICKET ? — UN SEUL ENDROIT LE DÉCIDE.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ────────────────────────────────────────────────────────────────
 *
 * La règle était écrite DEUX FOIS, à l'identique : dans `TicketProcessor` pour répondre à l'écran,
 * et dans `ValiderVenteService` pour marquer la vente imprimée. J'ai corrigé la première le 29/08
 * pour qu'une vente entièrement gratuite ne sorte pas de ticket, et j'ai laissé la seconde.
 *
 * Résultat : une vente gratuite en session était encore marquée « imprimée » — pour un ticket que le
 * même dépôt refusait désormais d'éditer. Une réédition ultérieure aurait annoncé DUPLICATA d'un
 * document qui n'a jamais existé.
 *
 * Trouvé par une session d'écran en branchant la caisse, pas en relisant le code. C'est la démonstration
 * du principe qu'on répète depuis trois jours : **une règle recopiée diverge**, et elle diverge au
 * premier correctif — pas au dixième.
 *
 * ── LES TROIS QUESTIONS NE SONT PAS LA MÊME ─────────────────────────────────────────────────────
 *
 * `estGratuite()` — le total est nul, donc aucun papier n'a de sens.
 * `impressionAutomatique()` — ce que l'écran doit faire sans rien demander.
 * `marqueImprimeeALaValidation()` — ce qu'on INSCRIT sur la vente, et qui exige en plus un comptoir.
 *
 * La troisième porte la nuance D44-bis : une vente directe n'a pas de session, donc pas d'imprimante.
 * La marquer imprimée écrirait un fait qui n'a pas eu lieu.
 */
final class TicketPrintingPolicy
{
    public function __construct(
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    /**
     * Une vente dont le total est nul : entrée offerte, invitation, badge de courtoisie.
     *
     * ⚠ C'est le TOTAL qui décide, jamais la présence d'une ligne à zéro. « Vendus seuls » est le
     * mot de la demande : un produit gratuit accompagné d'un payant sort un ticket normalement, et
     * il le doit — le client a payé quelque chose.
     */
    public function estGratuite(Vente $vente): bool
    {
        return $this->calculateur->centimes($vente->getTotal()) === 0;
    }

    /**
     * Le ticket sort-il sans qu'on demande ?
     *
     * ⚠ Le seuil par défaut d'un point de vente vaut 0,00, et la comparaison est `>=` : sans le test
     * de gratuité, une vente à 0 € donnerait `0 >= 0`, donc « au-dessus du seuil », donc impression.
     * Le défaut n'était pas dans le réglage — aucun seuil n'aurait produit le bon comportement, un
     * seuil strictement positif supprimant aussi le ticket des petites ventes payantes.
     */
    public function impressionAutomatique(Vente $vente): bool
    {
        if ($this->estGratuite($vente)) {
            return false;
        }

        $seuil = $this->calculateur->centimes($vente->getPointDeVente()?->getSeuilImpression() ?? '0.00');

        return $this->calculateur->centimes($vente->getTotal()) >= $seuil;
    }

    /**
     * Inscrit-on « imprimée » sur la vente à sa validation ?
     *
     * ⚠ D44-bis — UNE VENTE DIRECTE N'A PAS DE COMPTOIR, DONC PAS D'IMPRIMANTE. La marquer imprimée
     * écrirait un fait qui n'a pas eu lieu, et la réédition suivante annoncerait un duplicata d'un
     * document inexistant.
     */
    public function marqueImprimeeALaValidation(Vente $vente): bool
    {
        return $vente->getSession() !== null && $this->impressionAutomatique($vente);
    }
}
