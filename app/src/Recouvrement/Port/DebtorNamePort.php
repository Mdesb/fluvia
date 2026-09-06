<?php

declare(strict_types=1);

namespace App\Recouvrement\Port;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * COMMENT S'APPELLE CELUI QUI DOIT — porté par le module qui possède la donnée.
 *
 * ── CE QU'IL EXISTE POUR RÉPARER ───────────────────────────────────────────────────────────────
 *
 * L'écran Recouvrement affichait, sous un en-tête qui nomme une personne, `sport.abonnement_fitness`
 * suivi de douze caractères d'UUID. C'est sur cette ligne-là qu'un agent clique « Réglé » ou
 * « Rouvrir l'accès sans que la dette soit payée ». Il ne pouvait ni appeler ce client, ni le
 * retrouver dans l'écran Clients, ni le rapprocher du débiteur de la remise SEPA d'où vient le rejet.
 *
 * ⚠ ET LE FRONTAL CHERCHAIT DÉJÀ `nomRedevable`. Son helper lisait
 * `connu?.nomRedevable || connu?.libelleRedevable` — deux champs qu'aucun code du dépôt n'écrivait,
 * cherchés qui plus est sur la liste des incidents elle-même. Il retombait donc TOUJOURS sur la
 * référence brute. Un lecteur écrit en espérant une donnée que personne ne produit ne se signale
 * jamais : il rend simplement quelque chose de laid, et on s'y habitue.
 *
 * ── POURQUOI UN PORT SÉPARÉ DE `RedevablePort` ─────────────────────────────────────────────────
 *
 * ⚠ AJOUTER UNE MÉTHODE À `RedevablePort` AURAIT CHANGÉ DES PERMISSIONS. `crm.client` — le type que
 * produit tout rejet SEPA — n'a AUCUNE implémentation de `RedevablePort` aujourd'hui. En écrire une
 * pour obtenir un nom aurait aussi fourni `estLieA()`, que `RedevableSoiVoter` consulte : des
 * incidents invisibles à leur propre redevable seraient devenus visibles. C'est peut-être
 * souhaitable, mais c'est une décision de droits, pas un affichage de nom.
 *
 * Ce port ne sait qu'une chose et ne peut donc rien ouvrir.
 *
 * ── LA DIRECTION DE LA DÉPENDANCE EST CELLE DE `RedevablePort` ─────────────────────────────────
 *
 * Le module qui possède la donnée implémente le port ; `App\Recouvrement` ne dépend d'aucun d'eux.
 * C'est ce que dit `RedevablePort` : « c'est la verticale qui implémente ce port, jamais l'inverse ».
 */
#[AutoconfigureTag('recouvrement.debtor_name_port')]
interface DebtorNamePort
{
    /** Le type de contrat couvert, tel que `IncidentImpaye::$typeRedevable` le porte. */
    public function debtorType(): string;

    /**
     * Le nom lisible du redevable désigné, ou `null` quand la référence ne résout rien.
     *
     * ⚠ `null` N'EST PAS UNE CHAÎNE VIDE, ET L'ÉCRAN NE DOIT PAS LES CONFONDRE. Une référence qui ne
     * résout plus — contrat supprimé, client fusionné — n'est pas la même chose qu'un client sans
     * nom. La première demande qu'on aille voir ; la seconde est une fiche à compléter.
     */
    public function debtorName(string $debtorReference): ?string;
}
