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
     * @var list<array{country: string, category: VatRateCategory, rate: string, label: string, from: string, until: ?string, source: string}>
     */
    private const RATES = [
        [
            'country' => 'FR',
            'category' => VatRateCategory::Standard,
            'rate' => '20.00',
            'label' => 'Taux normal — la plupart des biens et services',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 278 (loi de finances rectificative 2012, en vigueur au 1er janvier 2014)',
        ],
        [
            'country' => 'FR',
            'category' => VatRateCategory::Reduced,
            'rate' => '10.00',
            'label' => 'Taux reduit — restauration, transport de voyageurs, travaux de renovation',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 279',
        ],
        [
            'country' => 'FR',
            'category' => VatRateCategory::SecondReduced,
            'rate' => '5.50',
            'label' => 'Taux reduit — produits alimentaires, livres, abonnements gaz et electricite',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 278-0 bis',
        ],
        [
            'country' => 'FR',
            'category' => VatRateCategory::SuperReduced,
            'rate' => '2.10',
            'label' => 'Taux particulier — medicaments remboursables, presse, certains spectacles',
            'from' => '2014-01-01',
            'until' => null,
            'source' => 'CGI art. 281 quater et suivants',
        ],
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
            $existant = $depot->findOneBy([
                'country' => $ligne['country'],
                'category' => $ligne['category'],
                'validFrom' => $from,
            ]);

            if (null !== $existant) {
                ++$deja;
                continue;
            }

            if ($simulation) {
                $io->text(sprintf(
                    'a creer : %s %s %% — %s (depuis le %s)',
                    $ligne['country'],
                    $ligne['rate'],
                    $ligne['label'],
                    $from->format('d/m/Y'),
                ));
                ++$crees;
                continue;
            }

            $taux = (new LegalVatRate())
                ->setCountry($ligne['country'])
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

        // ⚠ On le dit a chaque execution, et pas seulement dans la documentation : quelqu'un qui
        // lance cette commande croira le referentiel complet s'il n'entend pas le contraire.
        $io->warning(
            'Seule la France est semee. Les autres Etats membres demandent une source verifiee : '
            . 'un taux legal faux se reprend en confiance et finit sur des factures, alors qu\'un '
            . 'taux absent se saisit a la main comme aujourd\'hui.'
        );

        return Command::SUCCESS;
    }
}
