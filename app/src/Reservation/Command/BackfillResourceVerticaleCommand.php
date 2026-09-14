<?php

declare(strict_types=1);

namespace App\Reservation\Command;

use App\Fonctionnalite\Service\Fonctionnalites;
use App\Fonctionnalite\State\VocabularyProvider;
use App\Platform\Module\ModuleRegistry;
use App\Reservation\Entity\Ressource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `reservation:backfill-resource-verticale` (#100) : renseigne `Ressource.verticale` pour les
 * ressources qui ne l'ont pas encore, en la DÉRIVANT du métier unique de l'établissement.
 *
 * Le mot affiché par verticale (padel « Terrain », piscine « Bassin ») ne dépend de la ressource que
 * si celle-ci porte une `verticale`. Ce champ, ajouté au lot 1 (#123), n'était renseigné nulle part :
 * les écrans retombaient donc partout sur le défaut. Les ressources créées désormais par les
 * processeurs métier la portent (terrain padel → padel, visite guidée → musée) ; cette commande
 * comble l'EXISTANT.
 *
 * ── CE QU'ELLE FAIT, ET CE QU'ELLE NE FAIT PAS ─────────────────────────────────────────────────
 *
 * Deux sources, la plus SPÉCIFIQUE d'abord : (1) le `codeType` de la ressource quand il désigne sans
 * ambiguïté une verticale (`terrain_padel`/`coach_padel` → padel, `bassin`/`ligne_eau` → piscine,
 * `exposition`/`visite_guidee` → musée) — exact même sur un site MIXTE ; (2) sinon le métier unique de
 * l'établissement (même règle que le `courant` de l'API, `VocabularyProvider::verticaleUnique`), qui ne
 * tranche que pour un site mono. Un codeType générique (`terrain`, `salle`, `personnel`) sur un site
 * mixte reste `null`, à régler à la main (sélecteur sur la fiche ressource, écran Disponibilités). Elle
 * ne pose jamais une valeur FAUSSE — au pire, elle s'abstient.
 *
 * Idempotente : ne touche que les ressources à `verticale` nulle. `--dry-run` liste sans écrire.
 */
#[AsCommand(
    name: 'reservation:backfill-resource-verticale',
    description: "Renseigne la verticale des ressources qui n'en ont pas, dérivée du métier unique de l'établissement.",
)]
final class BackfillResourceVerticaleCommand extends Command
{
    /**
     * Les codeType sans ambiguïté minés par les processeurs de création et les fixtures, et leur
     * verticale (valeurs = `Metier::*->value`). Best-effort : un codeType absent d'ici retombe sur le
     * métier de l'établissement. Les codeType GÉNÉRIQUES (« terrain », « salle », « personnel ») en
     * sont volontairement exclus — ils ne désignent pas une verticale à eux seuls.
     *
     * @var array<string, string>
     */
    private const VERTICALE_PAR_CODE_TYPE = [
        'terrain_padel' => 'padel',
        'coach_padel' => 'padel',
        'bassin' => 'piscine',
        'ligne_eau' => 'piscine',
        'exposition' => 'musee',
        'visite_guidee' => 'musee',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Fonctionnalites $fonctionnalites,
        private readonly ModuleRegistry $registry,
    ) {
        parent::__construct();
    }

    /**
     * La verticale d'une ressource : d'abord son codeType (signal EXACT, valable même sur un site
     * mixte — un bassin est piscine quel que soit l'établissement), sinon le métier unique de
     * l'établissement (ne tranche que pour un site mono). `null` si aucun des deux ne décide.
     */
    public static function verticalePour(string $codeType, ?string $verticaleEtablissement): ?string
    {
        return self::VERTICALE_PAR_CODE_TYPE[$codeType] ?? $verticaleEtablissement;
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste ce qui serait renseigné, sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $verticales = VocabularyProvider::verticalesAvecVocabulaire($this->registry);

        /** @var list<Ressource> $ressources */
        $ressources = $this->em->getRepository(Ressource::class)->findBy(['verticale' => null]);

        if ($ressources === []) {
            $io->success('Aucune ressource sans verticale : rien à faire.');

            return Command::SUCCESS;
        }

        // Le métier unique se calcule PAR établissement ; on met le résultat en cache pour ne pas
        // réinterroger les fonctionnalités à chaque ressource du même établissement.
        $verticaleParEtablissement = [];
        $poses = 0;
        $abstenus = 0;

        foreach ($ressources as $ressource) {
            $etablissement = $ressource->getEtablissement();
            if ($etablissement === null) {
                ++$abstenus;
                continue;
            }

            $cle = (string) $etablissement->getId();
            if (!\array_key_exists($cle, $verticaleParEtablissement)) {
                $verticaleParEtablissement[$cle] = VocabularyProvider::verticaleUnique(
                    $this->fonctionnalites->actives($etablissement),
                    $verticales,
                );
            }
            $verticale = self::verticalePour($ressource->getCodeType(), $verticaleParEtablissement[$cle]);

            if ($verticale === null) {
                ++$abstenus;
                continue;
            }

            $source = isset(self::VERTICALE_PAR_CODE_TYPE[$ressource->getCodeType()]) ? 'codeType' : 'établissement';
            if (!$dryRun) {
                $ressource->setVerticale($verticale);
            }
            ++$poses;
            $io->writeln(sprintf('  %s « %s » → %s (par %s)', $dryRun ? '[à poser]' : '[posé]', $ressource->getLibelle(), $verticale, $source));
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d ressource(s) %s ; %d laissée(s) sans verticale (établissement mixte ou sans verticale unique).',
            $poses,
            $dryRun ? 'seraient renseignées' : 'renseignées',
            $abstenus,
        ));

        return Command::SUCCESS;
    }
}
