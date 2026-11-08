<?php

declare(strict_types=1);

namespace App\Acces\Port;

use App\Acces\Dto\ResultatCommande;
use App\Acces\Enum\CredentialEncoding;
use App\Acces\Hardware\CredentialRead;
use App\Acces\Hardware\EncodingPayload;

/**
 * Le poste d'encodage du comptoir : lire le badge ou le bracelet posé dessus, et, si le matériel le
 * sait, y écrire l'autorisation (#337, port séparé annoncé par ACC-2).
 *
 * Deux opérations distinctes, parce que deux parcs distincts :
 *  - **lire** suffit pour un badge reconnu par son numéro de série — c'est ce qui rend les badges
 *    déjà en circulation chez un client utilisables sans les réencoder ;
 *  - **écrire** n'a de sens que si le contrôleur de porte relit ce qui a été écrit. Un encodeur qui
 *    déclare `CredentialEncoding::None` n'écrit rien, et `write()` doit alors échouer explicitement
 *    plutôt que de rendre un succès sans effet (ACC-1).
 *
 * `station` désigne un poste d'encodage (un comptoir) ; sa correspondance avec une adresse matérielle
 * est l'affaire de l'adaptateur.
 */
interface CredentialEncoder
{
    /** Ce que ce poste sait faire. À déclarer au plus juste, comme `PiloteAcces::capabilities()`. */
    public function encoding(): CredentialEncoding;

    /** Le support actuellement posé sur le poste, ou `null` s'il n'y en a pas. */
    public function read(string $station): ?CredentialRead;

    /** Écrit l'autorisation sur le support posé. Échec explicite si le poste n'écrit pas. */
    public function write(string $station, EncodingPayload $payload): ResultatCommande;
}
