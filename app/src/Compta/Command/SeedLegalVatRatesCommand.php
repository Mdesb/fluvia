<?php

declare(strict_types=1);

namespace App\Compta\Command;

use App\Compta\Entity\LegalVatRate;
use App\Compta\Enum\VatRateCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Alimente le referentiel des taux de TVA legaux.
 *
 * ⚠ CE SEMIS NE COUVRE QUE LA FRANCE, ET C'EST UNE LIMITE ASSUMEE, PAS UN OUBLI.
 *
 * Maxime demande « tous les pays d'Europe ». Les mecanismes sont la — l'entite porte un pays, des
 * categories, des dates et une source ; l'ecran choisit un pays. Ce qui manque est la DONNEE, et
 * elle ne se devine pas : un taux legal faux est pire qu'un taux absent, parce qu'un taux absent se
 * saisit a la main tandis qu'un taux faux se reprend en confiance et se retrouve sur des factures.
 *
 * Les quatre taux francais ci-dessous sont ceux du CGI, avec leur article. Les vingt-six autres
 * Etats membres demandent une source verifiee — publication officielle de la Commission ou du
 * ministere concerne — et cette verification n'est pas un travail de developpeur. Tant qu'elle n'a
 * pas eu lieu, l'ecran affiche « aucun taux legal pour ce pays » et l'exploitant saisit a la main :
 * exactement ce qu'il fait aujourd'hui, donc rien n'est perdu.
 *
 * ── IDEMPOTENT PAR LA CLE METIER, PAS PAR UN DRAPEAU ────────────────────────────────────────────
 *
 * La cle est (pays, categorie, date d'entree en vigueur), et elle porte une contrainte d'unicite en
 * base. Relancer la commande ne cree donc rien de nouveau et ne modifie rien : une entree existante
 * est laissee telle quelle, y compris si sa valeur differe. C'est voulu — le referentiel est
 * immuable, et une commande qui « corrigerait » un taux existant serait precisement le geste qui
 * fait echouer la verification d'integrite NF525 sur les documents deja scelles.
 *
 * Un taux qui change par decret s'ajoute ici comme une NOUVELLE entree, et l'ancienne recoit sa date
 * de fin. La commande le fait quand la donnee le dit ; elle ne l'invente pas.
 */
#[AsCommand(
    name: 'vat:seed-legal-rates',
    description: 'Alimente le referentiel des taux de TVA legaux (France pour l\'instant).',
)]
final class SeedLegalVatRatesCommand extends Command
{
    /**
     * @var list<array{country: string, territory: string, category: VatRateCategory, rate: string, label: string, from: string, until: ?string, source: string}>
     */
    private const RATES = [
        [
            'country' => 'FR',
            'territory' => '',
            'category' => VatRateCategory::Standard,
            'rate' => '20.00',
            'label' => 'Taux normal — la plupart des biens et services',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 278 (loi de finances rectificative 2012, en vigueur au 1er janvier 2014)',
        ],
        [
            'country' => 'FR',
            'territory' => '',
            'category' => VatRateCategory::Reduced,
            'rate' => '10.00',
            'label' => 'Taux reduit — restauration, transport de voyageurs, travaux de renovation',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 279',
        ],
        [
            'country' => 'FR',
            'territory' => '',
            'category' => VatRateCategory::SecondReduced,
            'rate' => '5.50',
            'label' => 'Taux reduit — produits alimentaires, livres, abonnements gaz et electricite',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 278-0 bis',
        ],
        [
            'country' => 'FR',
            'territory' => '',
            'category' => VatRateCategory::SuperReduced,
            'rate' => '2.10',
            'label' => 'Taux particulier — medicaments remboursables, presse, certains spectacles',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 281 quater et suivants',
        ],

        // ── TERRITOIRES ────────────────────────────────────────────────────────────────────────
        //
        // ⚠ CES TAUX SE SEMENT A LA MAIN, ET C'EST LE SEUL MOYEN HONNETE.
        //
        // L'export TEDB rend `7,00` et `21,00` sous un seul `ES`, sans un mot sur le territoire :
        // ranger le 7 dans les Canaries serait exact, et ce serait MA connaissance, pas celle de la
        // source. Ici chaque ligne porte son texte, comme les taux francais du CGI.
        //
        // ⚠ ET UN TERRITOIRE QUI PORTE DES TAUX REND UNIQUEMENT LES SIENS (voir
        // `LegalVatRateRepository::baremeComplet()`). Ces jeux doivent donc etre COMPLETS pour leur
        // territoire, pas seulement porter les differences.

        // Espagne — peninsule et Baleares. Ce qui manquait pour que l'Espagne existe : son
        // catalogue etait VIDE depuis l'import, faute de pouvoir distinguer 21 % de 7 %.
        [
            'country' => 'ES',
            'territory' => '',
            'category' => VatRateCategory::Standard,
            'rate' => '21.00',
            'label' => 'Tipo general — peninsule et Baleares',
            'from' => '2012-09-01',
            'until' => null,
            'source' => 'Ley 37/1992 del IVA, art. 90 (modifie par le RDL 20/2012)',
        ],

        // Canaries. ⚠ CE N'EST PAS DE LA TVA, ET LE LIBELLE LE DIT.
        //
        // Les Canaries sont hors du territoire TVA de l'Union : l'impot qui s'y applique est
        // l'IGIC, un impot distinct. On le range ici parce que c'est ce qui figure sur une facture
        // canarienne a la place de la TVA — mais appeler « TVA » ce qui n'en est pas une, sans le
        // dire, ferait ecrire des mentions fausses.
        //
        // L'IGIC connait d'autres types (0 %, 9,5 %, 15 %) qui ne correspondent a aucune de nos
        // categories. Les forcer dans « super reduit » ou « parking » serait la meme inference que
        // celle refusee a l'import.
        [
            'country' => 'ES',
            'territory' => 'IC',
            'category' => VatRateCategory::Standard,
            'rate' => '7.00',
            'label' => 'IGIC tipo general — Canaries (impot distinct de la TVA)',
            'from' => '2020-01-01',
            'until' => null,
            'source' => 'Ley 20/1991 (IGIC), tipo general porte a 7 % au 1er janvier 2020',
        ],
        [
            'country' => 'ES',
            'territory' => 'IC',
            'category' => VatRateCategory::Reduced,
            'rate' => '3.00',
            'label' => 'IGIC tipo reducido — Canaries (impot distinct de la TVA)',
            'from' => '2020-01-01',
            'until' => null,
            'source' => 'Ley 20/1991 (IGIC), tipo reducido',
        ],

        // France d'outre-mer — Guadeloupe, Martinique, Reunion. DEUX taux, et deux seulement :
        // c'est precisement ce qui a fait abandonner la fusion categorie par categorie, laquelle y
        // faisait apparaitre le 5,5 % metropolitain.
        //
        // ⚠ GUYANE ET MAYOTTE N'Y SONT PAS : la TVA n'y est PAS APPLICABLE (CGI art. 294, 1).
        // Ce n'est pas un taux a zero, c'est une absence d'impot — et notre modele n'a pas de mot
        // pour ca. Un `DOM` unique les aurait fait facturer a 8,5 %.
        [
            'country' => 'FR',
            'territory' => 'DOM',
            'category' => VatRateCategory::Standard,
            'rate' => '8.50',
            'label' => 'Taux normal — Guadeloupe, Martinique, Reunion',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 296, 1° a',
        ],
        [
            'country' => 'FR',
            'territory' => 'DOM',
            'category' => VatRateCategory::Reduced,
            'rate' => '2.10',
            'label' => 'Taux reduit — Guadeloupe, Martinique, Reunion',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 296, 1° b',
        ],

        // Outre-mer, le taux de la presse. TEDB : « The super reduced rate in Martinique,
        // Guadeloupe and Reunion is 1.05% (for the press) ».
        [
            'country' => 'FR',
            'territory' => 'DOM',
            'category' => VatRateCategory::SuperReduced,
            'rate' => '1.05',
            'label' => 'Taux particulier presse — Guadeloupe, Martinique, Reunion',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 298 septies (taux applicable dans les DOM)',
        ],

        // ── LES QUATRE LIMITROPHES ──────────────────────────────────────────────────────────────
        //
        // ⚠ CE QUI SUIT NE VIENT PAS DE MA CONNAISSANCE : chaque valeur est celle de l'export
        // TEDB, et chaque `source` est le texte que TEDB cite lui-meme dans son champ `comments`,
        // taux par taux. Ce que l'import automatique ne pouvait pas faire, c'est CHOISIR entre deux
        // valeurs partageant la cle `Reduced rate` — c'est fait ici, en lisant ce qui les separe.
        //
        // L'Allemagne et l'Espagne ne figurent pas dans cette liste : leurs cles ne rendent qu'une
        // valeur chacune, donc `vat:import-tedb` les a deja posees sans ambiguite (DE 19/7,
        // ES 21/10/4). Rien a semer a la main la ou la source suffit.

        // Belgique — deux tableaux distincts de l'arrete royal n°20, donc deux taux distincts.
        [
            'country' => 'BE',
            'territory' => '',
            'category' => VatRateCategory::Reduced,
            'rate' => '12.00',
            'label' => 'Taux reduit — tableau B (margarine, charbon, logement social)',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'Arrete royal n° 20, tableau B (cite par TEDB)',
        ],
        [
            'country' => 'BE',
            'territory' => '',
            'category' => VatRateCategory::SecondReduced,
            'rate' => '6.00',
            'label' => 'Taux reduit — tableau A (alimentation, livres, transport, medicaments)',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'Arrete royal n° 20, tableau A (cite par TEDB)',
        ],

        // Italie — deux parties distinctes de la Tabella A, donc deux taux distincts. Le 4 % est
        // deja pose par l'import : sa cle `Super-reduced rate` ne rend qu'une valeur.
        [
            'country' => 'IT',
            'territory' => '',
            'category' => VatRateCategory::Reduced,
            'rate' => '10.00',
            'label' => 'Aliquota ridotta — Tabella A parte III (electricite, restauration, tourisme)',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'DPR 633/1972, Tabella A parte III (cite par TEDB)',
        ],
        [
            'country' => 'IT',
            'territory' => '',
            'category' => VatRateCategory::SecondReduced,
            'rate' => '5.00',
            'label' => 'Aliquota ridotta — Tabella A parte II-bis (services sociaux, diagnostic)',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'DPR 633/1972, Tabella A parte II-bis (cite par TEDB)',
        ],

        // Luxembourg. ⚠ SON 14 % N'EST PAS UN TAUX REDUIT, ET TEDB LE DIT DANS SON PROPRE
        // COMMENTAIRE — « Parking rate Solid mineral fuels; mineral oils » — alors qu'il le range
        // sous la cle `Reduced rate`. L'import l'a deja pose comme parking, via la cle
        // `Parking rate` qui rend la meme valeur. On ne le repose donc pas ici : ce serait un
        // second 14 % luxembourgeois, dans une autre categorie, pour le meme taux reel.
        [
            'country' => 'LU',
            'territory' => '',
            'category' => VatRateCategory::Reduced,
            'rate' => '8.00',
            'label' => 'Taux reduit — gaz, electricite, travaux de logement',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'Loi TVA du 12 fevrier 1979, art. 40 (cite par TEDB)',
        ],
        [
            'country' => 'LU',
            'territory' => '',
            'category' => VatRateCategory::SuperReduced,
            'rate' => '3.00',
            'label' => 'Taux super-reduit — alimentation, livres, transport, restauration',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'Loi TVA du 12 fevrier 1979, art. 40 (cite par TEDB)',
        ],

        // ⚠ LA CORSE N'Y EST PAS, ET LA RAISON EST MAINTENANT CHIFFREE.
        //
        // Un territoire declare son BAREME COMPLET (voir `LegalVatRateRepository::baremeComplet()`).
        // Celui de la Corse compte SIX taux : 20 % (droit commun, art. 278), 13 % (produits
        // petroliers), 10 % (travaux, materiel agricole), 5,5 % (droit commun), 2,10 % (certaines
        // operations) et 0,90 % (premieres representations, ventes d'animaux vivants) — CGI
        // art. 297, et TEDB les rend tous les six sous la meme cle `Reduced rate`.
        //
        // Notre modele offre CINQ cases par territoire : standard, parking, reduit, second reduit,
        // super reduit. Six valeurs n'y entrent pas. En laisser une dehors serait choisir laquelle
        // disparait, et le catalogue corse aurait l'air complet sans l'etre.
        //
        // Ce n'est donc pas une ligne a semer mais un besoin de MODELE : un taux attache a une
        // operation, pas a une categorie. Consigne ici plutot qu'ailleurs parce que c'est ici qu'on
        // viendra chercher « pourquoi la Corse manque ».
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait cree, sans rien ecrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');

        $depot = $this->em->getRepository(LegalVatRate::class);
        $crees = 0;
        $deja = 0;

        foreach (self::RATES as $ligne) {
            $from = new \DateTimeImmutable($ligne['from']);

            // ⚠ L'IDEMPOTENCE PORTE SUR LA LIGNE QU'ON POSE, PAS SUR UN VOISIN.
            //
            // On interroge exactement la cle metier de CETTE entree. Un test du genre « ce pays
            // a-t-il deja des taux ? » rendrait vrai des la premiere ligne et ferait sauter les
            // trois suivantes en silence — le semis paraitrait complet en ayant pose un quart.
            // ⚠ LE TERRITOIRE FAIT PARTIE DE LA CLE DEPUIS QU'IL EXISTE. Sans lui, le taux
            // canarien a 7 % et le taux espagnol a 21 % se confondent : le second serait vu
            // « deja present » et ne serait jamais pose, ou l'inverse — selon l'ordre du tableau.
            $existant = $depot->findOneBy([
                'country' => $ligne['country'],
                'territory' => $ligne['territory'],
                'category' => $ligne['category'],
                'validFrom' => $from,
            ]);

            if (null !== $existant) {
                ++$deja;
                continue;
            }

            if ($simulation) {
                $io->text(sprintf(
                    'a creer : %s%s %s %% — %s (depuis le %s)',
                    $ligne['country'],
                    $ligne['territory'] === '' ? '' : '/' . $ligne['territory'],
                    $ligne['rate'],
                    $ligne['label'],
                    $from->format('d/m/Y'),
                ));
                ++$crees;
                continue;
            }

            $taux = (new LegalVatRate())
                ->setCountry($ligne['country'])
                ->setTerritory($ligne['territory'])
                ->setCategory($ligne['category'])
                ->setRate($ligne['rate'])
                ->setLabel($ligne['label'])
                ->setValidFrom($from)
                ->setValidUntil(null === $ligne['until'] ? null : new \DateTimeImmutable($ligne['until']))
                ->setSource($ligne['source']);

            $this->em->persist($taux);
            ++$crees;
        }

        if (!$simulation) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d taux %s, %d deja presents.',
            $crees,
            $simulation ? 'a creer' : 'crees',
            $deja,
        ));

        // ⚠ ON LE DIT A CHAQUE EXECUTION, et pas seulement dans la documentation : quelqu'un qui
        // lance cette commande croira le referentiel complet s'il n'entend pas le contraire.
        //
        // ⚠ ET CE MESSAGE SE CALCULE, IL NE SE RECITE PAS. Il disait « Seule la France est semee »
        // — vrai le 01/09, faux le 02/09 des que l'Espagne et les DOM ont ete ajoutes, et rien
        // n'aurait signale le decalage : une phrase qui DECRIT un etat devient un mensonge le jour
        // ou l'etat change, et personne ne relit un avertissement qu'il a deja lu dix fois.
        $paysSemes = array_values(array_unique(array_map(
            static fn (array $l): string => $l['country'] . ($l['territory'] === '' ? '' : '/' . $l['territory']),
            self::RATES,
        )));
        sort($paysSemes);

        $io->warning(sprintf(
            'Cette commande ne seme que : %s. Les autres Etats membres n en font PAS partie et '
            . 'demandent une source verifiee — un taux legal faux se reprend en confiance et finit '
            . 'sur des factures, alors qu un taux absent se saisit a la main.',
            implode(', ', $paysSemes),
        ));

        $io->writeln('  Les taux STANDARD des 27 Etats membres s importent separement, depuis un');
        $io->writeln('  export officiel : <info>./infra/recuperer-taux-tva-ue.sh</info> puis <info>vat:import-tedb</info>.');

        return Command::SUCCESS;
    }
}
