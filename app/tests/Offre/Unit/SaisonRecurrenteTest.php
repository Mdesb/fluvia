<?php

declare(strict_types=1);

namespace App\Tests\Offre\Unit;

use App\Offre\Entity\Saison;
use PHPUnit\Framework\TestCase;

/**
 * « CHAQUE ANNÉE » ÉTAIT COCHABLE, S'ENREGISTRAIT, ET PERSONNE NE LA LISAIT.
 *
 * L'écran de paramètres propose la case depuis toujours, avec son aide : *« Évite de recréer la même
 * saison chaque année »*. Le champ partait bien en base, dans le groupe d'écriture. Et **aucun code
 * ne le lisait** — `contient()` comparait deux dates complètes, un point c'est tout.
 *
 * Une promesse creuse, longtemps sans conséquence. Puis la reconduction s'est mise à relire le prix
 * dans les saisons (03/09) : un exploitant qui **fait ce que l'écran lui dit** — cocher et ne rien
 * recréer — voyait ses reconductions s'arrêter au premier jour hors de l'intervalle.
 *
 * Mesuré par `allaccess-c0` avant que quiconque s'y fie, avec un témoin négatif : `getPriorite`
 * sort quatre fichiers, donc le grep voyait bien ; `recurrenceAnnuelle` en sortait zéro.
 */
final class SaisonRecurrenteTest extends TestCase
{
    /**
     * ⚠ **LE CAS QUI CASSE TOUT : une saison qui enjambe le nouvel an.**
     *
     * Des vacances du 15 décembre au 15 janvier donnent `1215` et `0115`. La comparaison naturelle
     * `début <= date <= fin` est alors FAUSSE TOUTE L'ANNÉE : elle exige d'être à la fois après
     * décembre et avant janvier. C'est le défaut classique, et une saison de vacances scolaires le
     * déclenche.
     */
    public function testUneSaisonRecurrenteQuiEnjambeLeNouvelAnContientLesDeuxCotes(): void
    {
        $vacances = $this->saison('2026-12-15', '2027-01-15', true);

        self::assertTrue($vacances->contient(new \DateTimeImmutable('2031-12-20')), 'Décembre, cinq ans plus tard.');
        self::assertTrue($vacances->contient(new \DateTimeImmutable('2031-01-05')), 'Janvier, de l autre côté du nouvel an.');
        self::assertTrue($vacances->contient(new \DateTimeImmutable('2026-12-15')), 'Le premier jour.');
        self::assertTrue($vacances->contient(new \DateTimeImmutable('2099-01-15')), 'Le dernier jour.');

        // ⚠ LE TÉMOIN : elle ne contient PAS tout. Un `return true` passerait les quatre assertions
        // ci-dessus, et ce serait pire que le défaut d'origine.
        self::assertFalse($vacances->contient(new \DateTimeImmutable('2031-06-15')), 'Juin n est pas dans les vacances de Noël.');
        self::assertFalse($vacances->contient(new \DateTimeImmutable('2031-01-16')), 'Le lendemain du dernier jour.');
    }

    /** Le cas ordinaire : une saison d'été revient chaque année sans enjamber quoi que ce soit. */
    public function testUneSaisonRecurrenteOrdinaireRevientChaqueAnnee(): void
    {
        $ete = $this->saison('2026-07-01', '2026-08-31', true);

        self::assertTrue($ete->contient(new \DateTimeImmutable('2030-07-15')));
        self::assertTrue($ete->contient(new \DateTimeImmutable('2024-08-31')), 'Une année ANTÉRIEURE : la récurrence vaut dans les deux sens.');
        self::assertFalse($ete->contient(new \DateTimeImmutable('2030-09-01')));
        self::assertFalse($ete->contient(new \DateTimeImmutable('2030-06-30')));
    }

    /**
     * ⚠ **LE TÉMOIN QUI PROTÈGE TOUT LE RESTE : sans la case, rien ne change.**
     *
     * Une récurrence appliquée à toutes les saisons ferait passer les tests ci-dessus et casserait
     * en silence chaque saison datée du dépôt — une promotion de janvier 2026 vaudrait pour 2027.
     */
    public function testUneSaisonNONRecurrenteResteBorneeAsonAnnee(): void
    {
        $promo = $this->saison('2026-07-01', '2026-08-31', false);

        self::assertTrue($promo->contient(new \DateTimeImmutable('2026-07-15')));
        self::assertFalse($promo->contient(new \DateTimeImmutable('2027-07-15')), 'Une saison datée ne déborde pas sur l année suivante.');
        self::assertFalse($promo->contient(new \DateTimeImmutable('2025-07-15')));
    }

    /**
     * ⚠ **`chevauche()` DOIT SUIVRE, SINON LE VALIDATEUR DEVIENT AVEUGLE.**
     *
     * `SaisonSansChevauchementValidator` empêche deux saisons de même priorité de se recouvrir. Tant
     * qu'aucune des deux méthodes ne lisait le champ, elles étaient d'accord entre elles — **deux
     * défauts qui s'annulent**. Corriger `contient()` seule aurait laissé le validateur passer VERT
     * sur un vrai conflit.
     */
    public function testUneSaisonRecurrenteChevaucheUneSaisonFixeDeLAnneeSuivante(): void
    {
        $recurrente = $this->saison('2026-07-01', '2026-08-31', true);
        $fixe2029 = $this->saison('2029-08-01', '2029-08-20', false);

        self::assertTrue($recurrente->chevauche($fixe2029), 'La récurrente revient en 2029 : elle recouvre bien cette saison.');
        self::assertTrue($fixe2029->chevauche($recurrente), 'Et la relation est symétrique.');

        // Le témoin : une saison fixe HORS de la fenêtre récurrente ne chevauche pas.
        $fixeHiver = $this->saison('2029-02-01', '2029-02-20', false);
        self::assertFalse($recurrente->chevauche($fixeHiver));
    }

    /**
     * ⚠ LE TÉMOIN QUE MES QUATRE ROUGES NE DONNAIENT PAS : deux saisons DATÉES ne se chevauchent pas.
     *
     * Casser `contient()` ou `chevauche()` fait tomber des tests — donc le mécanisme ATTRAPE. Aucun
     * ne prouvait qu'il ÉPARGNE. Si le mois-jour s'appliquait à toutes les saisons, en oubliant la
     * garde `$this->recurrenceAnnuelle || $autre->recurrenceAnnuelle`, une promotion de juillet 2027
     * serait déclarée en conflit avec celle de juillet 2026 : deux saisons qui ne partagent pas un
     * seul jour. `SaisonSansChevauchementValidator` refuserait l'enregistrement, et l'exploitant
     * n'aurait aucun moyen de comprendre pourquoi.
     *
     * ⚠ ET TOUS LES AUTRES TESTS SERAIENT RESTÉS VERTS. Un contrôle trop large est invisible à ses
     * tests de refus : le seul cas qui le démasque est celui qu'il doit AUTORISER. Signalé par
     * `allaccess-c0` en relisant le correctif.
     */
    public function testDeuxSaisonsDATEESDAnneesDifferentesNeSeChevauchentPas(): void
    {
        $promo2026 = $this->saison('2026-07-01', '2026-08-31', false);
        $promo2027 = $this->saison('2027-07-01', '2027-08-31', false);

        self::assertFalse(
            $promo2026->chevauche($promo2027),
            'même période, deux années : elles ne partagent aucun jour',
        );
        self::assertFalse($promo2027->chevauche($promo2026), 'et la relation est symétrique');

        // ⚠ LE TÉMOIN DU TÉMOIN : deux saisons datées qui SE RECOUVRENT VRAIMENT doivent, elles,
        // être vues. Sans ça, un `chevauche()` qui rendrait toujours `false` passerait le test
        // ci-dessus — et le validateur cesserait de protéger quoi que ce soit.
        $promoJuillet = $this->saison('2026-07-15', '2026-09-15', false);
        self::assertTrue(
            $promo2026->chevauche($promoJuillet),
            'témoin : deux saisons datées qui se recouvrent sont bien vues',
        );
    }

    /**
     * Une saison fixe de plus d'un an couvre tous les mois-jours.
     *
     * Sans ce cas, elle serait réduite à son mois-jour de départ et cesserait de chevaucher une
     * récurrente qu'elle contient pourtant en entier.
     */
    public function testUneSaisonFixeDePlusDUnAnChevauchetouteRecurrente(): void
    {
        $recurrente = $this->saison('2026-03-01', '2026-03-10', true);
        $longue = $this->saison('2028-01-01', '2029-06-30', false);

        self::assertTrue($longue->chevauche($recurrente));
        self::assertTrue($recurrente->chevauche($longue));
    }

    private function saison(string $debut, string $fin, bool $recurrente): Saison
    {
        return (new Saison())
            ->setNom('Saison d epreuve')
            ->setDateDebut(new \DateTimeImmutable($debut))
            ->setDateFin(new \DateTimeImmutable($fin))
            ->setRecurrenceAnnuelle($recurrente);
    }
}
