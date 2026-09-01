<?php

declare(strict_types=1);

namespace App\Sepa\Command;

use App\Sepa\Entity\MandatSepa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remet a zero les compteurs de collectes SEPA — TOUS, et la raison n'est pas un nettoyage.
 *
 * ── ⚠ POURQUOI « TOUS » ET NON « CEUX DE LA PREPROD » ───────────────────────────────────────────
 *
 * Mesure faite avant d'ecrire : dans tout le depot, UN SEUL endroit a jamais incremente
 * `nbCollectesReussies` — `GenerationRemiseHandler`, juste apres l'appel au port de transmission.
 * Or ce port n'a jamais transmis quoi que ce soit : `CollecteurSepaStubAdapter` rendait une
 * reference deterministe sans raccordement bancaire.
 *
 * **Aucune valeur non nulle, nulle part, n'a donc jamais correspondu a une collecte reelle.** Ce
 * n'est pas une correction approximative appliquee largement par prudence : c'est une donnee dont
 * on sait qu'aucune occurrence n'a jamais ete juste.
 *
 * ⚠ CE QUE LE COMPTEUR DECIDE, ET POURQUOI C'EST DE L'ARGENT. `SeqTpResolver` en deduit le type de
 * sequence SEPA : `RCUR` des qu'il depasse zero, `FRST` sinon. Un mandat compte a tort part en RCUR
 * a son PREMIER prelevement reel — motif de rejet bancaire classique, mandat par mandat, et qui ne
 * se manifeste qu'a la mise en service chez un client.
 *
 * Le correctif pose le 01/09 (`36c2db6`) empeche les faux comptages FUTURS. Il ne repare aucun de
 * ceux qui existent : cette commande est la moitie manquante.
 *
 * ── SIMULATION PAR DEFAUT ───────────────────────────────────────────────────────────────────────
 *
 * Sans `--ecrire`, rien n'est touche : la commande dit combien de mandats portent une valeur non
 * nulle et lesquels. Une ecriture de masse qu'on ne peut pas prevoir avant de la lancer est une
 * ecriture qu'on lance en esperant.
 *
 * ⚠ ET LE COMPTE RENDU PORTE SUR CE QUI A ETE ECRIT, PAS SUR CE QU'ON A DEMANDE. La commande relit
 * la base apres coup et annonce le nombre de lignes REELLEMENT remises a zero. « Fait » ne se
 * verifie pas apres coup sur une ecriture de masse ; un nombre relu, si.
 *
 * ── ⚠ CE QU'ELLE NE TOUCHE PAS, ET C'EST LE POINT LE PLUS IMPORTANT ────────────────────────────
 *
 * Une seule colonne : `nb_collectes_reussies`. Ni le RUM, ni l'IBAN chiffre, ni le statut, ni la
 * date de signature. Une commande de correction de masse qui deborde est pire que le defaut
 * qu'elle corrige, et elle deborde EN SILENCE — personne ne relit une colonne qu'on n'a pas
 * annoncee. `RemettreCompteursCollecteCommandTest` cloue cette epargne en comparant l'empreinte de
 * toutes les autres colonnes avant et apres.
 *
 * L'ecriture passe par un `UPDATE` cible plutot que par l'ORM : hydrater les mandats pour ne
 * modifier qu'un entier ferait passer les autres champs par le cycle de vie de Doctrine, donc par
 * d'eventuels ecouteurs — et l'epargne qu'on veut prouver dependrait alors de ce qu'ils font.
 */
#[AsCommand(
    name: 'sepa:compteurs:remettre-a-zero',
    description: 'Remet a zero les compteurs de collectes SEPA, qu\'aucune collecte reelle n\'a jamais alimentes.',
)]
final class RemettreCompteursCollecteCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ecrire', null, InputOption::VALUE_NONE, 'Applique. Sans cette option : constat seulement.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connexion = $this->em->getConnection();

        /** @var list<array{rum: string, nb: int}> $concernes */
        $concernes = $connexion->fetchAllAssociative(
            'SELECT rum, nb_collectes_reussies AS nb FROM sepa_mandat WHERE nb_collectes_reussies <> 0 ORDER BY rum'
        );

        $total = (int) $connexion->fetchOne('SELECT COUNT(*) FROM sepa_mandat');

        // ⚠ TEMOIN : LA MESURE A-T-ELLE LU QUELQUE CHOSE ? Une table vide et une requete cassee
        // rendent toutes deux « 0 concerne ». Le total distingue les deux, et sans lui un « rien a
        // faire » se lirait comme une preuve.
        $io->writeln(sprintf('%d mandat(s) en base.', $total));

        if ($concernes === []) {
            $io->success($total === 0
                ? 'Aucun mandat en base : rien a corriger, et rien n\'a ete mesure non plus.'
                : 'Aucun compteur non nul : rien a corriger.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('%d mandat(s) portent un compteur non nul', \count($concernes)));
        $io->table(['RUM', 'compteur'], array_map(
            static fn (array $l): array => [$l['rum'], (string) $l['nb']],
            $concernes,
        ));

        if (!$input->getOption('ecrire')) {
            $io->note('Constat seulement. Relance avec --ecrire pour appliquer.');

            return Command::SUCCESS;
        }

        $lignes = $connexion->executeStatement(
            'UPDATE sepa_mandat SET nb_collectes_reussies = 0 WHERE nb_collectes_reussies <> 0'
        );

        // ⚠ ON RELIT LA BASE PLUTOT QUE DE CROIRE LE RETOUR. Un `UPDATE` annonce des lignes
        // affectees ; il ne dit pas ce que la table contient ensuite. Sur une ecriture de masse,
        // c'est la relecture qui vaut compte rendu.
        $restants = (int) $connexion->fetchOne('SELECT COUNT(*) FROM sepa_mandat WHERE nb_collectes_reussies <> 0');

        if ($restants !== 0) {
            $io->error(sprintf(
                '%d ligne(s) ecrite(s), mais %d mandat(s) portent encore un compteur non nul. '
                . 'La correction est incomplete : ne pas considerer le sujet clos.',
                $lignes,
                $restants,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d compteur(s) remis a zero, verifie par relecture : plus aucun mandat ne porte de '
            . 'collecte non confirmee. Seule `nb_collectes_reussies` a ete ecrite.',
            $lignes,
        ));

        return Command::SUCCESS;
    }
}
