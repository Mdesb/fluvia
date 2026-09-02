<?php

declare(strict_types=1);

namespace App\Facturation\Command;

use App\Facturation\Einvoicing\CiiSerializer;
use App\Facturation\Einvoicing\InvoiceNotEmittableException;
use App\Facturation\Entity\Facture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ÉMETTRE LE FICHIER EUROPÉEN D'UNE FACTURE — ou dire précisément pourquoi c'est impossible.
 *
 * ⚠ UN SÉRIALISEUR QUE RIEN N'APPELLE EST DU CODE QUI N'EXISTE PAS. `CiiSerializer` produit le XML
 * exigé par EN 16931 ; sans cette commande, personne ne pourrait le déclencher, et il aurait rejoint
 * la famille des mécanismes complets et inatteignables que ce dépôt collectionne — la ressource de
 * paramétrage que rien n'appelait, l'identité de vendeur que rien n'écrivait.
 *
 * ── CE QU'ELLE PRODUIT, ET CE QU'ELLE NE PRODUIT PAS ────────────────────────────────────────────
 *
 * Elle écrit le **XML CII**. Elle n'écrit pas le PDF/A-3 dans lequel Factur-X l'enfouit : c'est un
 * lot distinct, qui n'est pas fait. Et elle ne dépose rien nulle part — ni Chorus, ni PDP. Le
 * fichier sort sur la sortie standard ou dans un fichier, et c'est un humain qui décide de sa suite.
 *
 * ⚠ **UN FICHIER PRODUIT N'EST PAS UN FICHIER CONFORME.** Les 28 termes obligatoires y sont, dans la
 * structure attendue. La centaine de règles métier d'EN 16931 et les restrictions de chaque CIUS
 * national ne sont vérifiées par personne ici. Cette commande ne doit jamais servir à dire « on est
 * conforme » : elle sert à voir ce qui sortirait.
 */
#[AsCommand(
    name: 'facturation:einvoicing:emettre',
    description: 'Produit le XML CII (EN 16931) d une facture, ou nomme les termes qui manquent.',
)]
final class EmitEuropeanInvoiceCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CiiSerializer $serialiseur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('numero', InputArgument::REQUIRED, 'Le numéro de la facture (ex. FA-2026-0001).')
            ->addOption('vers', null, InputOption::VALUE_REQUIRED, 'Écrire dans ce fichier au lieu de la sortie standard.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $numero = (string) $input->getArgument('numero');

        $facture = $this->em->getRepository(Facture::class)->findOneBy(['numero' => $numero]);

        if (!$facture instanceof Facture) {
            // ⚠ ON NOMME CE QU'ON A CHERCHÉ. « Facture introuvable » laisse croire à une erreur de
            // droit d'accès autant qu'à une faute de frappe.
            $io->error(sprintf('Aucune facture ne porte le numéro « %s ».', $numero));

            return Command::FAILURE;
        }

        try {
            $xml = $this->serialiseur->serialize($facture);
        } catch (InvoiceNotEmittableException $refus) {
            // ⚠ LE REFUS N'EST PAS UN INCIDENT D'EXÉCUTION, C'EST LE RÉSULTAT DE LA MESURE.
            //
            // Il dit ce qui manque et où. Le présenter comme une erreur technique ferait chercher un
            // bug là où il y a une saisie à faire.
            $io->warning('Cette facture ne peut pas être émise au format européen.');
            $io->writeln($refus->getMessage());
            $io->writeln('');
            $io->writeln('  L état d ensemble : <info>facturation:einvoicing:etat</info>');

            return Command::FAILURE;
        }

        $vers = $input->getOption('vers');

        if (\is_string($vers) && $vers !== '') {
            if (file_put_contents($vers, $xml) === false) {
                $io->error(sprintf('Impossible d écrire dans « %s ».', $vers));

                return Command::FAILURE;
            }

            $io->success(sprintf('%s écrit (%d octets).', $vers, \strlen($xml)));
            $io->writeln('  ⚠ Ce fichier n a été soumis à AUCUN validateur : il porte les termes obligatoires,');
            $io->writeln('    il ne prouve pas la conformité.');

            return Command::SUCCESS;
        }

        $output->writeln($xml);

        return Command::SUCCESS;
    }
}
