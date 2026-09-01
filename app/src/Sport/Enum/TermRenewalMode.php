<?php

declare(strict_types=1);

namespace App\Sport\Enum;

use App\Offre\Entity\Formule;

/**
 * CE QUI ARRIVE A UN ABONNEMENT LE JOUR OU SON ENGAGEMENT SE TERMINE.
 *
 * Arbitre par Maxime le 01/09/2026 : « ca doit etre un parametre, ca peut etre les trois ». Le
 * choix se porte sur la FORMULE, pas sur l'abonnement -- deux adherents de la meme formule doivent
 * connaitre le meme sort, sinon la promesse commerciale ne veut plus rien dire.
 *
 * ── OU IL VIT, ET POURQUOI PAS DANS UNE COLONNE NEUVE ───────────────────────────────────────────
 *
 * `Formule.renouvellement` est deja un JSON libre, documente `{auto, prix, nbRenouvellements?,
 * emailNotif}` -- et jamais lu par personne. On y ajoute une cle plutot qu'une colonne : le champ
 * existe pour ca, et une migration de plus sur une table de configuration n'apporterait rien.
 *
 * ⚠ ON NE LIT PAS `auto: true` COMME « RECONDUIRE », ET C'EST LE POINT LE PLUS IMPORTANT DE CE
 * FICHIER.
 *
 * Les trois formules de la preproduction portent `{"auto":true,"prix":"fixe"}` a l'identique,
 * ecrit par des fixtures. Personne n'a jamais choisi cette valeur pour une formule en
 * particulier, et rien ne l'a jamais lue. La traiter comme un choix reviendrait a imposer un
 * reengagement d'un an a tous les adherents existants a partir d'un drapeau decoratif -- une
 * decision commerciale prise par un effet de bord.
 *
 * Le mode est donc une cle EXPLICITE. Son absence vaut `Monthly`, le seul des trois qui ne prive
 * personne d'acces du jour au lendemain et n'engage personne pour un an.
 */
enum TermRenewalMode: string
{
    /** Nouvel engagement de meme duree, echeancier complet. C'est ce que les formules AFFICHENT. */
    case Renew = 'reconduire';

    /** Roulement d'une periode a la fois : l'adherent reste, paie au mois, part quand il veut. */
    case Monthly = 'mensuel';

    /** L'abonnement passe « echu » et l'acces s'arrete. Personne n'entre sans payer. */
    case Suspend = 'suspendre';

    public const CLE_FORMULE = 'modeAuTerme';

    /**
     * Le mode d'une formule, avec son repli sur `Monthly`.
     *
     * ⚠ UN MODE INCONNU N'EST PAS UNE ERREUR SILENCIEUSE ICI. Il retombe sur `Monthly` comme une
     * absence -- parce que ce lecteur tourne dans une tache planifiee qui traite des centaines
     * d'abonnements : lever pour une formule mal configuree arreterait le traitement de toutes les
     * autres. L'appelant signale l'ecart, il ne s'y arrete pas.
     */
    public static function forFormule(?Formule $formule): self
    {
        $brut = $formule?->getRenouvellement()[self::CLE_FORMULE] ?? null;

        return \is_string($brut) ? (self::tryFrom($brut) ?? self::Monthly) : self::Monthly;
    }

    /** Vrai si la formule a explicitement choisi : sert a distinguer un repli d'une decision. */
    public static function isExplicit(?Formule $formule): bool
    {
        $brut = $formule?->getRenouvellement()[self::CLE_FORMULE] ?? null;

        return \is_string($brut) && self::tryFrom($brut) !== null;
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Renew => 'Reconduit pour un nouvel engagement',
            self::Monthly => 'Poursuivi au mois, sans engagement',
            self::Suspend => "Suspendu : l'acces s'arrete au terme",
        };
    }
}
