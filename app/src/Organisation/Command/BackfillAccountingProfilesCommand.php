<?php

declare(strict_types=1);

namespace App\Organisation\Command;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Service\AccountingChartSeeder;
use App\Offre\Service\AccountingCategorySeeder;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * REPRISE : les structures ouvertes AVANT que l'ouverture ne pose leur profil comptable.
 *
 * ── POURQUOI ELLE EXISTE ────────────────────────────────────────────────────────────────────────
 *
 * `StructureOnboarding` crée désormais le profil exploitant et les taux de TVA — mais il s'exécute
 * à l'OUVERTURE. Les structures déjà créées ne repasseront jamais dessus : elles restent bloquées
 * sur « Avant de pouvoir vendre — 2 sur 3 », avec un bouton « Créer un taux » qui ne peut pas
 * aboutir puisqu'un taux exige un profil qu'aucun écran ne crée.
 *
 *   > Corriger la fabrique ne répare pas ce qu'elle a déjà produit.
 *
 * ── CE QU'ELLE NE FAIT PAS, ET C'EST LE POINT DÉLICAT ───────────────────────────────────────────
 *
 * **Elle n'écrase rien.** Un établissement qui a déjà un profil est laissé tel quel — même si son
 * type paraît discutable. Un exploitant qui a saisi ses propres taux ne doit pas les voir doublés
 * par un seed qui passe derrière lui : le doublon d'un taux de TVA ne se voit pas dans une liste et
 * se voit très bien sur une facture.
 *
 * De même, un profil sans aucun taux reçoit les taux manquants, jamais ceux qu'il a déjà — la
 * comparaison porte sur la VALEUR du taux, pas sur son libellé, qu'un exploitant a pu renommer.
 *
 * ── PAR DÉFAUT ELLE NE FAIT RIEN ────────────────────────────────────────────────────────────────
 *
 * Sans `--ecrire`, elle liste ce qu'elle ferait. Une commande de reprise qui écrit dès qu'on la
 * lance est une commande qu'on n'ose plus exécuter pour regarder.
 */
#[AsCommand(
    name: 'organisation:reprendre-profils-compta',
    description: 'Crée le profil exploitant et les taux de TVA des structures qui n’en ont pas.',
)]
final class BackfillAccountingProfilesCommand extends Command
{
    /** @var list<array{0: string, 1: string}> */
    private const TAUX_TVA_FRANCE = [
        ['20.00', 'Taux normal 20 %'],
        ['10.00', 'Taux intermédiaire 10 %'],
        ['5.50', 'Taux réduit 5,5 %'],
        ['2.10', 'Taux particulier 2,1 %'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountingChartSeeder $chartSeeder,
        private readonly AccountingCategorySeeder $categorySeeder,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ecrire', null, InputOption::VALUE_NONE, 'Applique réellement (sinon simple constat).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ecrire = (bool) $input->getOption('ecrire');

        // ── LES CATEGORIES COMPTABLES SONT GLOBALES : UNE FOIS, PAS PAR ETABLISSEMENT ────────
        //
        // Elles sont de portee socle (D51), donc partagees par tout le parc. Les poser dans la
        // boucle ci-dessous les creerait une fois puis les retrouverait douze fois -- correct mais
        // trompeur a la lecture du rapport, qui semblerait dire qu'il y avait douze choses a faire.
        $categoriesManquantes = $this->categorySeeder->manquants();
        if ($ecrire && $categoriesManquantes !== []) {
            $this->categorySeeder->poser();
        }

        $etablissements = $this->em->getRepository(Etablissement::class)->findAll();

        $profilsCrees = 0;
        $tauxCrees = 0;
        $lignes = [];

        $comptesEtJournauxCrees = 0;

        foreach ($etablissements as $etablissement) {
            $profil = $this->em->getRepository(ProfilExploitant::class)
                ->findOneBy(['etablissementPrincipal' => $etablissement]);

            $creeProfil = false;
            if (!$profil instanceof ProfilExploitant) {
                // ⚠ Le SIREN vient de l'identité légale si elle existe. Sans lui, le profil ne
                // passerait pas la validation — on ne fabrique pas un numéro d'entreprise, on
                // signale et on laisse l'exploitant compléter.
                $siren = $this->siren($etablissement);
                if ($siren === null) {
                    $lignes[] = [$etablissement->getNom(), '—', 'SIREN introuvable : à compléter à la main'];
                    continue;
                }

                $profil = (new ProfilExploitant())
                    ->setSiren($siren)
                    ->setEtablissementPrincipal($etablissement)
                    ->setType(TypeExploitant::GroupePrive)
                    ->setReferentielComptable(ReferentielComptable::Pcg);

                $creeProfil = true;
                ++$profilsCrees;
                if ($ecrire) {
                    $this->em->persist($profil);
                }
            }

            $manquants = $this->tauxManquants($profil, $creeProfil);
            $tauxCrees += \count($manquants);

            if ($ecrire) {
                foreach ($manquants as [$taux, $libelle]) {
                    $this->em->persist(
                        (new TauxTva())->setProfilExploitant($profil)->setTaux($taux)->setLibelle($libelle)->setActif(true)
                    );
                }
            }

            // ⚠ LE PROFIL ET LES TAUX NE SUFFISENT PAS.
            //
            // Les régimes résolvent leurs comptes par préfixe (511, 411, 4457, 487, 512, 706) et
            // leurs journaux par code (VTE, ENC, REG, PCA, EXT) : sans eux, la génération
            // d'écritures lève une 422. Poser le profil seul déplace le message d'erreur, il ne
            // débloque rien — c'est ce que j'avais annoncé comme « mise en service débloquée » alors
            // que quatre maillons sur six manquaient encore.
            //
            // Le CONSTAT lit `manquants()`, la POSE appelle `poser()` : même source, donc le mode
            // constat ne peut pas annoncer autre chose que ce que le mode écriture ferait.
            $chartManquants = $this->chartSeeder->manquants($profil);
            $aPoser = \count($chartManquants['journaux']) + \count($chartManquants['comptes']);
            $comptesEtJournauxCrees += $aPoser;

            if ($ecrire && $aPoser > 0) {
                $this->chartSeeder->poser($profil);
            }

            if ($creeProfil || $manquants !== [] || $aPoser > 0) {
                $lignes[] = [
                    $etablissement->getNom(),
                    $creeProfil ? 'profil créé' : 'profil existant',
                    \count($manquants) . ' taux à poser',
                    sprintf(
                        '%d journal(aux), %d compte(s)',
                        \count($chartManquants['journaux']),
                        \count($chartManquants['comptes']),
                    ),
                ];
            }
        }

        if ($categoriesManquantes !== []) {
            $io->text(sprintf(
                '%d catégorie(s) comptable(s) de socle %s : %s',
                \count($categoriesManquantes),
                $ecrire ? 'posée(s)' : 'à poser',
                implode(', ', $categoriesManquantes),
            ));
        }

        if ($ecrire) {
            $this->em->flush();
        }

        $io->table(['Établissement', 'Profil', 'Taux', 'Plan de comptes'], $lignes);
        $io->writeln(sprintf('  %d profil(s), %d taux, %d objet(s) de plan comptable.', $profilsCrees, $tauxCrees, $comptesEtJournauxCrees));

        if (!$ecrire) {
            $io->note('Constat seulement. Relance avec --ecrire pour appliquer.');
        }

        return Command::SUCCESS;
    }

    /**
     * Les taux légaux que ce profil n'a pas encore.
     *
     * La comparaison porte sur la VALEUR, jamais sur le libellé : un exploitant a pu renommer
     * « Taux normal 20 % » en « TVA 20 » — c'est le même taux, et le reposer le doublerait.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function tauxManquants(ProfilExploitant $profil, bool $profilNeuf): array
    {
        $existants = [];
        if (!$profilNeuf) {
            foreach ($this->em->getRepository(TauxTva::class)->findBy(['profilExploitant' => $profil]) as $taux) {
                $existants[] = $taux->getTaux();
            }
        }

        $manquants = [];
        foreach (self::TAUX_TVA_FRANCE as [$taux, $libelle]) {
            if (!\in_array($taux, $existants, true)) {
                $manquants[] = [$taux, $libelle];
            }
        }
        if (!\in_array('0.00', $existants, true)) {
            $manquants[] = ['0.00', TauxTva::LIBELLE_HORS_CHAMP];
        }

        return $manquants;
    }

    private function siren(Etablissement $etablissement): ?string
    {
        $identite = $this->em->getRepository(\App\Legal\Entity\LegalIdentity::class)
            ->findOneBy(['establishment' => $etablissement]);

        if ($identite === null) {
            return null;
        }

        $siret = preg_replace('/\D/', '', (string) $identite->getSiret()) ?? '';
        $siren = substr($siret, 0, 9);

        return \strlen($siren) === 9 ? $siren : null;
    }
}
