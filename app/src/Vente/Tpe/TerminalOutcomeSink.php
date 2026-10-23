<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Vente\Service\PaymentAttemptStore;
use Symfony\Component\Uid\Uuid;

/**
 * Le chemin UNIQUE de l'issue d'un terminal (spec TPE lot 1, « enregistrerIssue ») : un connecteur,
 * un webhook, un worker ou le caissier qui lit le terminal y déposent ce qu'il a rendu.
 *
 * Il ne fait passer la tentative que depuis un statut sans issue, par un `UPDATE` conditionnel, et le
 * règlement porte la clé unique de la tentative : une issue livrée deux fois, une réponse tardive et
 * une déclaration ne peuvent écrire qu'un règlement. Celle qui perd n'écrit rien, mais ce qu'elle
 * disait reste noté sur la tentative, pour le rapprochement.
 *
 * @phpstan-import-type Attempt from PaymentAttemptStore
 */
interface TerminalOutcomeSink
{
    /**
     * @param string    $attemptId  l'identifiant de la tentative, en hexadécimal
     * @param Uuid|null $declaredBy le caissier, quand c'est lui qui a lu le terminal
     *
     * @return Attempt|null la tentative relue après coup ; nulle si elle n'existe pas
     */
    public function record(string $attemptId, ResultatTpe $resultat, ?Uuid $declaredBy = null): ?array;
}
