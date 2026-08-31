<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Facturation\Entity\DestinataireFacturation;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * RG-FACT-08 : un destinataire de facture doit être identifiable et adressable.
 *
 * ── LA RÈGLE EXISTAIT, ÉCRITE ET JUSTE, ET N'AVAIT AUCUN APPELANT ───────────────────────────────
 *
 * `DestinataireFacturation::anomalies()` implémente la règle depuis l'origine — personne morale :
 * raison sociale + SIRET ; particulier : un nom ; adresse requise dans les deux cas, parce que c'est
 * une mention légale obligatoire. Relevé par `allaccess-b8` avec témoin : **une définition, zéro
 * appel**.
 *
 * État mesuré en base le 31/08 : **trois destinataires, trois sans adresse, deux sans nom.** Aucune
 * facture émise aujourd'hui ne serait conforme.
 *
 * ⚠ C'est la troisième fois en deux jours qu'on trouve un contrôle présent, correct, et hors du
 * moment où il sert. Un contrôle qui ne tourne pas rend un vert au nom d'une vérification qui n'a
 * pas eu lieu.
 *
 * ── POURQUOI À L'ÉMISSION, ET POURQUOI ÇA NE CASSE RIEN ─────────────────────────────────────────
 *
 * L'émission est irréversible : c'est le dernier moment où un refus vaut quelque chose. Après, la
 * seule correction possible est un avoir.
 *
 * La réserve naturelle — « bloquer à l'émission va rendre des clients existants infacturables » — ne
 * s'applique pas, et c'est une mesure qui le dit, pas une intuition. `DestinataireFacturation` n'est
 * **pas une ressource partagée** : pas d'`ApiResource`, un `JoinColumn nullable:false` par facture,
 * et `copier()` en fait une copie profonde « jamais partagée entre deux documents ». C'est un
 * instantané recopié à chaque facture.
 *
 * Le rayon d'action réel d'un refus est donc : **les émissions futures, plus un brouillon.**
 * Les factures déjà émises ne repassent pas par là.
 *
 * ── CE QUE CE GARDE NE REMPLACE PAS ─────────────────────────────────────────────────────────────
 *
 * `allaccess-89` marque déjà les manques à l'écran — `[destinataire non renseigné]`,
 * `[adresse non renseignée]`, imprimés tous les deux. Leur phrase mérite d'être gardée :
 * **« un marquage dit ce qui manque, il n'empêche pas d'émettre. »** Les deux sont nécessaires et
 * aucun ne fait le travail de l'autre.
 */
final class RecipientCompletenessGuard
{
    /**
     * Refuse une émission dont le destinataire ne serait pas conforme, en nommant CE QUI MANQUE.
     *
     * ⚠ Le message liste toutes les anomalies d'un coup. Les rendre une par une obligerait
     * l'exploitant à réessayer autant de fois qu'il manque de champs — et c'est le genre de friction
     * qui fait chercher un contournement plutôt qu'une correction.
     */
    public function assertComplete(?DestinataireFacturation $recipient): void
    {
        if (!$recipient instanceof DestinataireFacturation) {
            throw new UnprocessableEntityHttpException(
                'Émission impossible : la facture n\'a pas de destinataire. '
                .'Un document commercial doit désigner celui à qui il est adressé (RG-FACT-08).'
            );
        }

        $anomalies = $recipient->anomalies();

        if ($anomalies === []) {
            return;
        }

        throw new UnprocessableEntityHttpException(
            'Émission impossible, le destinataire est incomplet (RG-FACT-08) : '
            .implode(' ', $anomalies)
        );
    }
}
