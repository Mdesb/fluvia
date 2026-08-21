<?php

declare(strict_types=1);

namespace App\Platform\Message;

/**
 * Marqueur : ce message part en asynchrone (D7-bis).
 *
 * **Pourquoi un marqueur et pas une liste de classes dans la configuration.** Une liste se périme dès
 * qu'un module ajoute un message et oublie de l'y inscrire — et l'oubli ne se voit pas : le message
 * part simplement en synchrone, c'est-à-dire qu'un appel d'API externe s'exécute dans la transaction
 * d'un utilisateur qui a cliqué. Le symptôme est un ralentissement inexplicable, puis un verrou de
 * base sous charge. Avec un marqueur, l'auteur du message déclare son intention là où il écrit le
 * code, et il ne peut pas l'oublier ailleurs.
 *
 * **Ce qui a le droit de passer par là.** Du travail **sortant** : appeler une API externe,
 * provisionner un établissement, envoyer une notification. Pas des faits du domaine — ceux-là restent
 * sur le bus synchrone, dans la transaction, parce que c'est ce qui garantit qu'un fait et ses
 * conséquences internes réussissent ou échouent ensemble.
 *
 * **Et une règle qui vaut d'être dite : on dépêche après le commit.** Un message envoyé dans une
 * transaction qui déroule ensuite met en file du travail pour un fait qui n'a jamais eu lieu — on
 * provisionnerait un établissement pour un paiement annulé.
 */
interface AsyncMessage
{
}
