<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Le pilote sait-il **écrire** une autorisation sur un médium (D17, axe 3) ?
 *
 * À ne pas confondre avec l'appairage : appairer associe un identifiant déjà lu à un droit stocké
 * côté serveur ; encoder inscrit l'autorisation elle-même sur la carte. Deux opérations distinctes,
 * d'où le port d'encodage séparé (ACC-2).
 */
enum CredentialEncoding: string
{
    /** Le pilote lit des identifiants, il n'écrit rien. Cas de tous nos pilotes actuels. */
    case None = 'none';

    /** Le pilote commande un encodeur capable d'écrire une autorisation sur un support. */
    case Writes = 'writes';
}
