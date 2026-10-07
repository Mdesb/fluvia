<?php

declare(strict_types=1);

namespace App\Reservation\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `reservation:backfill-resource-occupancy` : remet `Ressource.occupationCourante` sur ce que les
 * réservations disent réellement.
 *
 * ── POURQUOI UN RATTRAPAGE ─────────────────────────────────────────────────────────────────────
 *
 * Mesuré le 15/09/2026 : deux chemins de création sur sept incrémentaient ce compteur, alors que
 * TOUTES les annulations le décrémentent. Annuler une réservation venue d'un autre chemin — OTA
 * musée, OTA boutique, commande boutique, confirmation de groupe, padel — retirait une unité que
 * personne n'avait posée. Les sept chemins incrémentent désormais, mais les compteurs déjà en base
 * portent la dérive : elle ne se corrige pas toute seule, et `GREATEST(…, 0)` la masque à zéro.
 *
 * ── CE QUE LE COMPTEUR VAUT, ET POURQUOI CE SONT CES DEUX STATUTS ──────────────────────────────
 *
 * Il compte les unités TENUES sur la ressource porteuse : la somme des `quantity` des réservations
 * `a_confirmer` et `confirmee` posées sur ses créneaux (les siens, et ceux de ses sous-ressources).
 * `honoree` et `no_show_facture` en sont exclus, parce que `BasculerNoShowCommand` décrémente au
 * moment où il les pose ; les annulées le sont pour la même raison.
 *
 * Une sous-ressource ne porte pas la jauge (`Ressource::ressourcePorteuseJauge()` rend sa mère) :
 * son compteur est remis à zéro, pour qu'une valeur oubliée ne laisse pas croire le contraire.
 *
 * Idempotente : relancée, elle ne trouve plus d'écart. `--dry-run` liste sans écrire.
 */
#[AsCommand(
    name: 'reservation:backfill-resource-occupancy',
    description: "Recalcule le compteur d'occupation des ressources à partir des réservations qui tiennent une place.",
)]
final class BackfillResourceOccupancyCommand extends Command
{
    /** Les statuts qui TIENNENT une place — les deux que le compteur suit. */
    private const STATUTS_TENUS = ['a_confirmer', 'confirmee'];

    public function __construct(
        private readonly Connection $connexion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les écarts sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');

        $ecarts = $this->ecarts();

        if ($ecarts === []) {
            $io->success('Aucun écart : les compteurs correspondent déjà aux réservations.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Ressource', 'Libellé', 'Compteur', 'Réservations', 'Écart'],
            array_map(
                static fn (array $ligne): array => [
                    substr($ligne['id'], 0, 8),
                    $ligne['libelle'],
                    (string) $ligne['compteur'],
                    (string) $ligne['attendu'],
                    sprintf('%+d', $ligne['attendu'] - $ligne['compteur']),
                ],
                $ecarts,
            ),
        );

        if ($simulation) {
            $io->note(sprintf('%d ressource(s) à corriger. Rien n\'a été écrit (--dry-run).', \count($ecarts)));

            return Command::SUCCESS;
        }

        foreach ($ecarts as $ligne) {
            $this->connexion->executeStatement(
                'UPDATE reservation_ressource SET occupation_courante = ? WHERE id = UNHEX(?)',
                [$ligne['attendu'], $ligne['id']],
            );
        }

        $io->success(sprintf('%d compteur(s) remis sur les réservations.', \count($ecarts)));

        return Command::SUCCESS;
    }

    /**
     * Les ressources dont le compteur diffère de ce que disent les réservations.
     *
     * @return list<array{id: string, libelle: string, compteur: int, attendu: int}>
     */
    private function ecarts(): array
    {
        $marqueurs = implode(', ', array_fill(0, \count(self::STATUTS_TENUS), '?'));

        // Les unités tenues remontent à la ressource PORTEUSE : la mère quand il y en a une, la
        // ressource elle-même sinon — la même règle que `Ressource::ressourcePorteuseJauge()`.
        $sql = <<<SQL
            SELECT LOWER(HEX(r.id)) AS id,
                   r.libelle AS libelle,
                   r.occupation_courante AS compteur,
                   COALESCE(tenues.unites, 0) AS attendu
            FROM reservation_ressource r
            LEFT JOIN (
                SELECT COALESCE(porteuse.ressource_mere_id, porteuse.id) AS porteuse_id,
                       SUM(res.quantity) AS unites
                FROM reservation_reservation res
                INNER JOIN reservation_creneau c ON c.id = res.creneau_id
                INNER JOIN reservation_ressource porteuse ON porteuse.id = c.ressource_id
                WHERE res.statut IN ($marqueurs)
                GROUP BY COALESCE(porteuse.ressource_mere_id, porteuse.id)
            ) AS tenues ON tenues.porteuse_id = r.id
            WHERE r.occupation_courante <> COALESCE(tenues.unites, 0)
            ORDER BY r.libelle
            SQL;

        /** @var list<array{id: string, libelle: string, compteur: int|string, attendu: int|string}> $lignes */
        $lignes = $this->connexion->executeQuery($sql, self::STATUTS_TENUS)->fetchAllAssociative();

        return array_map(
            static fn (array $ligne): array => [
                'id' => (string) $ligne['id'],
                'libelle' => (string) $ligne['libelle'],
                'compteur' => (int) $ligne['compteur'],
                'attendu' => (int) $ligne['attendu'],
            ],
            $lignes,
        );
    }
}
