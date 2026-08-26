<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * L'état d'une pièce commerciale, et les seuls passages autorisés (FAC-1).
 *
 * **Les transitions vivent ici**, comme pour l'abonnement : c'est le seul endroit où l'on peut lire,
 * d'un coup d'œil, qu'un devis refusé ne redevient pas accepté, et le seul à corriger le jour où
 * cette règle change.
 *
 * **`Converted` n'est pas `Accepted`.** Un devis accepté attend qu'on en fasse une commande ; un devis
 * converti l a déjà produite. Les confondre ferait fabriquer deux commandes pour un devis, ce qui se
 * découvre à la livraison.
 */
enum DocumentStatus: string
{
    /** Composé, pas encore envoyé au client. Modifiable. */
    case Draft = 'draft';

    /** Envoyé au client. **Figé** : on ne modifie pas une pièce que le client a reçue. */
    case Issued = 'issued';

    /** Le client a dit oui. En attente de la pièce suivante. */
    case Accepted = 'accepted';

    /** Le client a dit non. État final. */
    case Rejected = 'rejected';

    /** La date de validité est passée sans réponse. N'existe que pour un devis. */
    case Expired = 'expired';

    /** La pièce suivante a été produite depuis celle-ci. État final. */
    case Converted = 'converted';

    /** Annulé avant conversion. État final. */
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Draft => [self::Issued, self::Cancelled],
            self::Issued => [self::Accepted, self::Rejected, self::Expired, self::Cancelled],
            // Accepté puis converti : c'est le chemin nominal. Annulable tant que rien n'a suivi.
            self::Accepted => [self::Converted, self::Cancelled],
            self::Rejected, self::Expired, self::Converted, self::Cancelled => [],
        };
    }

    /** Une pièce figée ne se modifie plus : elle se refait, ou se corrige par la suivante. */
    public function estFige(): bool
    {
        return self::Draft !== $this;
    }

    /** Seule une pièce acceptée produit la suivante. */
    public function peutEtreConvertie(): bool
    {
        return self::Accepted === $this;
    }
}
