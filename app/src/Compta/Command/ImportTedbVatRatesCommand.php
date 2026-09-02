<?php

declare(strict_types=1);

namespace App\Compta\Command;

use App\Compta\Entity\LegalVatRate;
use App\Compta\Enum\VatRateCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * IMPORTE LES TAUX DE TVA EUROPÉENS DEPUIS UN EXPORT DE TEDB.
 *
 * TEDB — *Taxes in Europe Database* — est la base de la Commission européenne (DG TAXUD). C'est la
 * seule source à la fois officielle, exhaustive et exploitable par une machine que j'aie trouvée.
 *
 * ── ⚠ ELLE LIT UN FICHIER, ELLE N'INTERROGE PAS LE RÉSEAU ──────────────────────────────────────
 *
 * L'interface REST de TEDB n'est pas documentée publiquement : je l'ai découverte en lisant le
 * JavaScript de l'application et en interceptant sa propre requête. Elle répond, elle est servie par
 * la Commission — mais rien ne garantit que son chemin ou sa forme survivront à la prochaine version.
 *
 * Une commande qui l'appellerait à chaud casserait un jour sans prévenir, au milieu d'une mise en
 * service. La récupération est donc un geste SÉPARÉ et auditable — `infra/recuperer-taux-tva-ue.sh`
 * écrit un fichier daté ; cette commande le lit. Le fichier est la preuve de ce qu'on a importé.
 *
 * ── ⚠ ELLE NE TOUCHE PAS AUX ENTRÉES DÉJÀ SOURCÉES ─────────────────────────────────────────────
 *
 * Les taux français posés par `vat:seed-legal-rates` citent le CGI **article par article** — une
 * source meilleure que « TEDB au 02/09 ». Les écraser remplacerait une référence légale par une
 * référence à une base de données.
 *
 * L'idempotence porte donc sur la clé métier exacte (pays, catégorie, taux, date de début), et une
 * entrée déjà présente n'est jamais réécrite.
 *
 * ── ⚠ CE QU'ELLE NE FAIT PAS : DEVINER LES SOUS-CATÉGORIES ─────────────────────────────────────
 *
 * TEDB ne distingue que `STANDARD` et `REDUCED`. Notre énumération est plus fine — taux super-réduit,
 * taux parking, taux zéro. Ranger un taux réduit dans l'une de ces cases demanderait de savoir
 * POURQUOI il est réduit, ce que l'export ne dit pas.
 *
 * On importe donc ce que la source affirme, et rien de plus. Un taux mal catégorisé serait pire
 * qu'un taux rangé grossièrement : il porterait une affirmation que personne n'a vérifiée.
 */
#[AsCommand(
    name: 'vat:import-tedb',
    description: 'Importe les taux de TVA europeens depuis un export TEDB (Commission europeenne).',
)]
final class ImportTedbVatRatesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'L export JSON produit par infra/recuperer-taux-tva-ue.sh.')
            ->addOption('a-blanc', null, InputOption::VALUE_NONE, 'Montrer ce qui serait importe, sans rien ecrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chemin = (string) $input->getArgument('fichier');
        $aBlanc = (bool) $input->getOption('a-blanc');

        if (!is_file($chemin)) {
            $io->error(sprintf('Fichier introuvable : %s', $chemin));
            $io->writeln('  Le recuperer : <info>./infra/recuperer-taux-tva-ue.sh</info>');

            return Command::FAILURE;
        }

        /** @var array{result?: list<array<string, mixed>>} $donnees */
        $donnees = json_decode((string) file_get_contents($chemin), true) ?: [];
        $resultats = $donnees['result'] ?? [];

        // ⚠ PLANCHER : un export vide produirait « 0 importe » et se lirait comme « rien a faire ».
        // L'Union compte 27 Etats membres ; moins de vingt entrees veut dire qu'on n'a pas mesure.
        if (\count($resultats) < 20) {
            $io->error(sprintf(
                'Export inexploitable : %d entree(s). Un export complet en compte au moins une par pays et par type.',
                \count($resultats),
            ));
            $io->writeln("  Un fichier tronque produirait un referentiel incomplet qui aurait l'air complet.");

            return Command::FAILURE;
        }

        $recupereLe = date('Y-m-d', (int) filemtime($chemin));
        $poses = 0;
        $deja = 0;
        $ignores = 0;
        $parPays = [];
        $nonClassables = [];
        $ambigus = [];
        $divergences = [];

        // ── ON GROUPE D'ABORD, ON ECRIT ENSUITE ────────────────────────────────────────────────
        //
        // ⚠ LA BASE IMPOSE (pays, categorie, date) UNIQUE, ET LA SOURCE NE LE RESPECTE PAS.
        //
        // ⚠⚠ CORRECTION DU 02/09, ET ELLE PORTE SUR CE QUI ETAIT ECRIT ICI MEME.
        //
        // Ce commentaire affirmait : « TEDB ne dit pas lequel est le second reduit, le super
        // reduit ou le parking ». C'est FAUX, et cette phrase a servi a justifier l'exclusion de
        // 1 114 taux reduits pendant deux heures.
        //
        // TEDB le dit : chaque taux porte un champ `key` — `Reduced rate`, `Super-reduced rate`,
        // `Parking rate`, `Exempted`, `Not applicable`, `Out of scope`. Je lisais le `type` du BLOC
        // (`STANDARD` / `REDUCED`), qui est grossier, et je n'ai jamais ouvert la cle de chaque
        // taux. J'ai conclu « la source ne le dit pas » d'une lecture partielle de la source.
        //
        // Ce qui reste vrai apres correction, et qui est mesure :
        //
        //   — 20 paires (pays, cle) rendent UNE valeur : elles s'importent, classees par la source ;
        //   — 22 en rendent PLUSIEURS sous la meme cle. Et la cause n'est pas un manque de
        //     classement : ce sont des TERRITOIRES aplatis. La France sort six « Reduced rate » —
        //     13 et 0,9 pour la Corse, 8,5 et 1,05 pour les DOM, 10 et 5,5 pour la metropole — sans
        //     un mot sur le territoire. Le Portugal sort 22 et 16, qui sont Madere et les Acores.
        //   — l'Espagne garde ses DEUX taux STANDARD a la meme date, 7 % et 21 % : les Canaries et
        //     la peninsule, sous un seul code ISO.
        //
        // On construit donc la liste complete avant d'ecrire quoi que ce soit, et on n'importe que
        // ce qui rend UNE valeur. Le reste est compte et nomme : un import qui choisirait pour nous
        // produirait un referentiel plein, plausible, et faux la ou ca compte.
        $groupes = [];

        foreach ($resultats as $bloc) {
            $pays = strtoupper((string) ($bloc['isoCode'] ?? ''));

            // ⚠ DEUX CARACTERES, SINON L'IMPORT ENTIER MEURT AU FLUSH. `country` est un
            // VARCHAR(2) : un code plus long ne provoque pas une ligne rejetee, il provoque un
            // SQLSTATE[22001] au milieu du flush, et les vingt-sept lignes valides de la meme
            // transaction ne sont jamais ecrites. Une seule entree deformee dans l'export suffirait
            // a rendre l'import inoperant, avec pour tout diagnostic « Data too long for column ».
            if ($pays === '' || \strlen($pays) !== 2) {
                ++$ignores;
                continue;
            }

            foreach ($bloc['rates'] ?? [] as $taux) {
                $categorie = self::categoriePourTaux(
                    \is_string($taux['key'] ?? null) ? $taux['key'] : null,
                    (string) ($bloc['type'] ?? ''),
                );

                // ⚠ CE QU'ON LAISSE DEHORS SE COMPTE ET SE DIT. Un import silencieux donnerait un
                // referentiel qui a l'air complet, et personne ne saurait ce qui n'y est pas.
                if ($categorie === null) {
                    $nonClassables[$pays] = ($nonClassables[$pays] ?? 0) + 1;
                    continue;
                }

                $valeur = $taux['value'] ?? null;
                $depuis = self::date((string) ($taux['situationOn'] ?? ''));

                if (!is_numeric($valeur) || $depuis === null) {
                    ++$ignores;
                    continue;
                }

                // ⚠ LE TERRITOIRE RESTE VIDE, ET L'AMBIGUITE ESPAGNOLE DEMEURE.
                //
                // Le referentiel sait desormais distinguer les Canaries de la peninsule — mais
                // l'export TEDB, lui, ne le dit toujours pas : il rend `7,00` et `21,00` sous un
                // seul `ES`, sans un mot sur le territoire. Ranger le 7 dans `IC` serait exact,
                // et ce serait MA connaissance, pas celle de la source.
                //
                // L'import continue donc de signaler l'Espagne au lieu de la trancher. Les taux
                // territoriaux se posent a la main, avec leur texte legal, comme les taux francais
                // du CGI — c'est le seul endroit ou une telle affirmation a une reference.
                $cle = $pays . '||' . $categorie->value . '|' . $depuis->format('Y-m-d');
                $groupes[$cle]['pays'] = $pays;
                $groupes[$cle]['categorie'] = $categorie;
                $groupes[$cle]['depuis'] = $depuis;
                $groupes[$cle]['nom'] = (string) ($bloc['countryName'] ?? $pays);
                $groupes[$cle]['valeurs'][number_format((float) $valeur, 2, '.', '')] = true;
            }
        }

        $depot = $this->em->getRepository(LegalVatRate::class);

        foreach ($groupes as $g) {
            $valeurs = array_keys($g['valeurs']);

            // ⚠ PLUSIEURS VALEURS POUR UNE MEME CLE : ON N'EN CHOISIT AUCUNE.
            if (\count($valeurs) !== 1) {
                sort($valeurs);
                $ambigus[] = sprintf('%s %s : %s', $g['pays'], $g['categorie']->value, implode(' / ', $valeurs));
                continue;
            }

            $formate = $valeurs[0];

            // ── ⚠ L'IDEMPOTENCE NE PEUT PAS PORTER SUR LA DATE ──────────────────────────────
            //
            // Le premier reflexe — « cette (pays, categorie, date) existe-t-elle ? » — est faux ici,
            // et le dire coute une phrase parce que ca ne se voit pas a l'execution.
            //
            // La France porte deja `standard 20,00 au 2014-01-01, source CGI art. 278`. TEDB rend
            // `standard 20,00 au 2026-07-01`. Les dates different, donc un test sur la date ne trouve
            // rien, et on POSE une seconde ligne. Le referentiel resout par date : c'est desormais la
            // ligne TEDB qui s'applique, et la citation du CGI ne sert plus a rien. Aucune erreur,
            // aucun doublon, aucun test rouge — juste une reference legale remplacee par une
            // reference a une base de donnees, en silence, sur la meme valeur.
            //
            // On regarde donc TOUTES les lignes de la (pays, categorie), pas celle du jour.
            // Le droit commun seulement : un taux des Canaries ne doit ni empecher l'import du
            // taux espagnol de droit commun, ni etre compte comme « deja present » pour lui.
            $existantes = $depot->findBy(['country' => $g['pays'], 'territory' => '', 'category' => $g['categorie']]);

            if ($existantes !== []) {
                $memeValeur = false;
                foreach ($existantes as $e) {
                    if (number_format((float) $e->getRate(), 2, '.', '') === $formate) {
                        $memeValeur = true;
                        break;
                    }
                }

                if ($memeValeur) {
                    // Rien a apprendre : la ligne en place dit la meme chose, et le plus souvent
                    // mieux (le CGI cite un article, TEDB cite un export).
                    ++$deja;
                    continue;
                }

                // ⚠ VALEUR DIFFERENTE D'UNE LIGNE DEJA SOURCEE A LA MAIN. C'est soit un changement
                // de taux reel, soit une erreur de l'une des deux sources. Les deux meritent d'etre
                // vues ; aucune ne se tranche par un import.
                $divergences[] = sprintf(
                    '%s %s : en base %s, TEDB %s au %s',
                    $g['pays'],
                    $g['categorie']->value,
                    implode(' / ', array_map(
                        static fn ($e): string => number_format((float) $e->getRate(), 2, '.', ''),
                        $existantes,
                    )),
                    $formate,
                    $g['depuis']->format('Y-m-d'),
                );
                continue;
            }

            ++$poses;
            $parPays[$g['pays']] = ($parPays[$g['pays']] ?? 0) + 1;

            if ($aBlanc) {
                continue;
            }

            $this->em->persist(
                (new LegalVatRate())
                    ->setCountry($g['pays'])
                    ->setCategory($g['categorie'])
                    ->setRate($formate)
                    ->setLabel(sprintf('%s — %s', $g['categorie']->value, $g['nom']))
                    ->setValidFrom($g['depuis'])
                    ->setSource(sprintf(
                        'TEDB (Commission europeenne, DG TAXUD), export du %s — https://ec.europa.eu/taxation_customs/tedb/',
                        $recupereLe,
                    ))
            );
        }

        if (!$aBlanc) {
            $this->em->flush();
        }

        ksort($parPays);

        $io->success(sprintf(
            '%d taux %s, %d deja presents, %d ignores. %d pays concernes.',
            $poses,
            $aBlanc ? 'seraient importes' : 'importes',
            $deja,
            $ignores,
            \count($parPays),
        ));

        $io->writeln('  ' . implode('  ', array_map(
            static fn (string $p, int $n): string => sprintf('%s:%d', $p, $n),
            array_keys($parPays),
            $parPays,
        )));

        $io->writeln('');

        if ($divergences !== []) {
            sort($divergences);
            $io->warning(sprintf(
                '%d taux DIVERGENT d une ligne deja en base — rien n a ete ecrit :',
                \count($divergences),
            ));
            foreach ($divergences as $d) {
                $io->writeln('      ' . $d);
            }
            $io->writeln('');
            $io->writeln('  ⚠ Soit le taux a change, soit une des deux sources se trompe. Un import qui');
            $io->writeln('    ecraserait la ligne en place remplacerait une citation du CGI par une');
            $io->writeln('    citation de base de donnees. A trancher a la main.');
            $io->writeln('');
        }

        if ($ambigus !== []) {
            sort($ambigus);
            $io->warning(sprintf('%d cle(s) rendent PLUSIEURS taux — aucune n a ete importee :', \count($ambigus)));
            foreach ($ambigus as $a) {
                $io->writeln('      ' . $a);
            }
            $io->writeln('');
            $io->writeln("  ⚠ L'Espagne en est l'exemple : 7 % et 21 % a la meme date, les Canaries et la");
            $io->writeln('    peninsule sous un seul code ISO. Le referentiel n accepte qu une valeur par');
            $io->writeln('    (pays, categorie, date) ; choisir laquelle est un arbitrage, pas un import.');
            $io->writeln('');
        }

        if ($nonClassables !== []) {
            ksort($nonClassables);
            $io->warning(sprintf(
                '%d taux NON CLASSABLES ecartes, sur %d pays — ils ne sont PAS dans le referentiel.',
                array_sum($nonClassables),
                \count($nonClassables),
            ));
            $io->writeln('  ' . implode('  ', array_map(
                static fn (string $p, int $n): string => sprintf('%s:%d', $p, $n),
                array_keys($nonClassables),
                $nonClassables,
            )));
            $io->writeln('');
            $io->writeln('  ⚠ Ce sont les cles `Exempted`, `Not applicable` et `Out of scope`.');
            $io->writeln('');
            $io->writeln('    `Exempted` range sous un seul mot DEUX choses que notre referentiel separe a');
            $io->writeln("    dessein : l'exoneration AVEC droit a deduction et l'exoneration SANS. Les deux");
            $io->writeln('    rendent zero euro de TVA sur la facture du client, et elles changent ce que');
            $io->writeln("    l'exploitant peut RECUPERER. Les distinguer demanderait de lire un commentaire");
            $io->writeln('    en texte libre, redige pays par pays — et se tromper produirait un taux qui a');
            $io->writeln("    l'air juste et fait perdre de la deduction sans que rien ne le signale.");
            $io->writeln('');
            $io->writeln("    `Not applicable` et `Out of scope` ne sont pas des taux.");
            $io->writeln('');
        }

        $io->writeln('  ⚠ Les taux francais poses par `vat:seed-legal-rates` citent le CGI article par');
        $io->writeln('    article — une source meilleure. Ils ne sont jamais reecrits.');

        return Command::SUCCESS;
    }

    /**
     * ⚠ ON NE TRADUIT QUE CE QUE LA SOURCE DIT. Un type inconnu est ignore et compte, jamais range
     * dans la categorie la plus proche : une correspondance approximative sur un referentiel fiscal
     * produit une affirmation que personne n'a verifiee.
     */
    /**
     * LA CLASSIFICATION VIENT DE `rates[].key`, PAS DU `type` DU BLOC.
     *
     * Le bloc ne dit que `STANDARD` ou `REDUCED` — grossier. Chaque taux, lui, porte sa cle :
     * `Reduced rate`, `Super-reduced rate`, `Parking rate`, `Exempted`, `Not applicable`,
     * `Out of scope`, et rien (le taux normal). C'est le champ que je n'avais pas ouvert, et dont
     * l'absence supposee a servi a ecarter 1 114 taux.
     *
     * ── ⚠ `Exempted` N'EST PAS IMPORTE, ET C'EST LA DECISION LA PLUS DELICATE DE CETTE METHODE ──
     *
     * TEDB range sous cette cle DEUX choses que notre enumeration separe a dessein : l'exoneration
     * AVEC droit a deduction (notre `Zero`) et l'exoneration SANS (notre `Exempt`). Les journaux
     * belges y figurent avec le commentaire « Exemption with deduction right » ; d'autres lignes
     * n'ont pas cette mention.
     *
     * Les deux rendent zero euro de TVA sur la facture du client. Elles changent ce que
     * l'exploitant peut RECUPERER. Trancher entre elles demanderait de lire un champ de commentaire
     * en texte libre, en anglais, redige pays par pays — et se tromper produirait un taux qui a
     * l'air juste et fait perdre de la deduction sans que rien ne le signale.
     *
     * `Not applicable` et `Out of scope` sont ecartes pour une raison plus simple : ce ne sont pas
     * des taux.
     */
    private static function categoriePourTaux(?string $cle, string $type): ?VatRateCategory
    {
        if ($cle === null || $cle === '') {
            // Pas de cle : c'est le taux normal, et seulement si le bloc l'annonce.
            return strtoupper($type) === 'STANDARD' ? VatRateCategory::Standard : null;
        }

        return match ($cle) {
            'Reduced rate' => VatRateCategory::Reduced,
            'Super-reduced rate' => VatRateCategory::SuperReduced,
            'Parking rate' => VatRateCategory::Parking,
            default => null,
        };
    }

    /** L'export date en `AAAA/MM/JJ`. */
    private static function date(string $brut): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y/m/d', $brut);

        return $d === false ? null : $d;
    }
}
