<?php

declare(strict_types=1);

namespace App\Vente\Port;

/**
 * Objet-valeur « moyen de paiement » lu du référentiel M6 (RG-M2-02) — n'est PAS une entité M2.
 * Le code du moyen est stocké en clair sur le Paiement (pas de FK dure vers M6).
 */
final class MoyenPaiement
{
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly bool $autoriseRendu = false,
        public readonly bool $exigeReference = false,
        public readonly bool $autoriseDiffere = false,
    ) {
    }

    /**
     * Moyens qui se remettent **en main propre** et se déposent en banque.
     *
     * **Ce n'est pas la liste des moyens qui autorisent le rendu de monnaie**, et la distinction n'est
     * pas théorique : quatre de ces cinq codes ont `autoriseRendu = false`. Une première version de
     * `estFiduciaire()` renvoyait `autoriseRendu` — elle classait donc les quatre chèques comme non
     * fiduciaires, et une vente directe les aurait acceptés **sans que personne ne détienne le
     * papier**. Relevé par `claude-A` sur pièce dans `ComptaFixtures`, avant fusion.
     *
     * Un chèque n'est pas une écriture, c'est un objet : il se reçoit, se garde, se compte, se remet
     * en banque. La phrase qui fonde D44-bis — *sans espèces, rien à compter, donc rien à clôturer* —
     * devient fausse dès qu'un chèque entre : il y a bien quelque chose à compter, et personne pour en
     * répondre.
     *
     * Ne sont **pas** fiduciaires, et chacun pour une raison qu'on peut dire : `cb` et `payfip`
     * (transaction électronique), `virement` (mouvement bancaire), `pmv` (débit d'un compte client,
     * voir `PaiementHandler`), `avoir` (écriture interne), `differe` (promesse, pas instrument).
     *
     * @var list<string>
     */
    private const CODES_FIDUCIAIRES = ['especes', 'cheque', 'cheque_vacances', 'cheque_culture', 'cheque_loisirs'];

    /**
     * Le moyen se remet-il **physiquement**, de sorte qu'il faille quelqu'un pour le détenir ?
     *
     * C'est la question qui décide si une vente peut se passer de session de caisse (D44-bis). Le
     * critère est écrit, pas déduit : `autoriseRendu` avait la bonne réponse pour les espèces et la
     * mauvaise pour les chèques, et celui qui aurait lu le proxy dans six mois aurait refait la même
     * déduction fausse.
     *
     * **Une liste de codes reste un pis-aller, et il faut le dire.** Un exploitant qui ajoute un
     * instrument papier à son référentiel ne sera pas couvert : la propriété appartient au moyen, donc
     * elle appartient à `Compta\Entity\MoyenPaiement` (demande écrite à `claude-D` le 26/08). En
     * attendant, `MoyenFiduciaireTest` parcourt le référentiel standard et **échoue sur tout code
     * non classé** — de sorte que l'ajout force une décision au lieu de passer en silence.
     */
    public function estFiduciaire(): bool
    {
        return \in_array($this->code, self::CODES_FIDUCIAIRES, true);
    }

    /** @return list<string> Les codes fiduciaires du référentiel standard, pour les contrôles. */
    public static function codesFiduciaires(): array
    {
        return self::CODES_FIDUCIAIRES;
    }
}
