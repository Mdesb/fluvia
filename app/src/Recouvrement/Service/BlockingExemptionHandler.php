<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Entity\BlockingExemption;
use App\Securite\Entity\Utilisateur;

/**
 * Accorde ou retire une exemption durable, ET RÉÉVALUE LA PORTE DANS LA FOULÉE.
 *
 * ── ⚠ POURQUOI CETTE CLASSE EXISTE : LA GARDE N'AVAIT PAS DE DÉCLENCHEUR ───────────────────────
 *
 * `PropagationAccesHandler::reevaluer()` consulte bien l'exemption — mais elle n'est appelée que par
 * trois chemins, tous des ÉVÉNEMENTS DE RECOUVREMENT : une représentation réussie, un impayé résolu,
 * une réouverture forcée. Accorder une exemption n'en est pas un.
 *
 * Conséquence, si l'on s'en tenait là : **le cas qui a motivé la fonctionnalité ne serait pas
 * traité.** La collectivité qu'on veut exempter est DÉJÀ BLOQUÉE au moment où on le décide ; poser
 * l'exemption n'aurait rouvert sa porte qu'au prochain incident réglé, c'est-à-dire des semaines
 * plus tard, ou jamais. L'exemption n'aurait servi qu'aux impayés futurs — exactement ce que la
 * garde dans `reevaluer()` était censée éviter.
 *
 * La garde était nécessaire et pas suffisante. Il lui fallait ce déclencheur.
 *
 * ── ⚠ POURQUOI ICI ET NON DANS LE REGISTRE ────────────────────────────────────────────────────
 *
 * `PropagationAccesHandler` dépend déjà de `BlockingExemptionRegistry` : lui faire appeler la
 * propagation en retour fermerait un cycle que le conteneur refuserait. Le registre reste ce qu'il
 * doit être — il répond « exempté ou non » et tient l'unicité — et l'orchestration vit ici.
 *
 * ── LE RETRAIT RÉÉVALUE AUSSI, ET C'EST LA MOITIÉ QU'ON OUBLIE ────────────────────────────────
 *
 * Retirer une exemption doit refermer la porte si des impayés bloquants subsistent. Sans ça, on
 * retirerait l'exemption d'un client qui doit de l'argent et sa porte resterait ouverte —
 * silencieusement, jusqu'au prochain incident. C'est le même défaut que celui corrigé le 30/08,
 * dans l'autre sens.
 *
 * ⚠ `reevaluer()` est le bon appel dans LES DEUX cas, et c'est ce qui rend cette classe courte :
 * elle ne décide pas de l'état de la porte, elle demande qu'il soit recalculé. À la pose comme au
 * retrait, la réponse juste est « regarde ce qui reste dû, et conclus ».
 */
final class BlockingExemptionHandler
{
    public function __construct(
        private readonly BlockingExemptionRegistry $registry,
        private readonly PropagationAccesHandler $propagation,
    ) {
    }

    /**
     * Exempte durablement un redevable, et rouvre sa porte immédiatement s'il était bloqué.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException si déjà exempté
     */
    public function accorder(
        string $typeRedevable,
        string $referenceRedevable,
        string $motif,
        Etablissement $etablissement,
        ?Utilisateur $agent,
    ): BlockingExemption {
        $exemption = $this->registry->accorder($typeRedevable, $referenceRedevable, $motif, $etablissement, $agent);

        // La porte se recalcule MAINTENANT : `reevaluer()` verra l'exemption et ouvrira, quel que
        // soit le nombre d'impayés en cours. Sans cette ligne, l'exemption ne vaudrait que pour
        // l'avenir et le client resterait dehors.
        $this->propagation->reevaluer($typeRedevable, $referenceRedevable);

        return $exemption;
    }

    /**
     * Retire l'exemption, et referme la porte si des impayés bloquants subsistent.
     *
     * ⚠ On ne ferme pas ; on RÉÉVALUE. Un client exempté qui n'avait aucun impayé en cours ne doit
     * pas se retrouver bloqué parce qu'on a retiré son exemption — il n'y avait rien à bloquer.
     */
    public function retirer(BlockingExemption $exemption, ?Utilisateur $agent): BlockingExemption
    {
        $retiree = $this->registry->retirer($exemption, $agent);

        $this->propagation->reevaluer($exemption->getDebtorType(), $exemption->getDebtorRef());

        return $retiree;
    }
}
