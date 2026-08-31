<?php

declare(strict_types=1);

namespace App\Facturation\Command;

use App\Facturation\Einvoicing\BusinessTerm;
use App\Facturation\Einvoicing\InvoiceReadiness;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\StatutFacture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `facturation:einvoicing:etat` — ce qui manque pour émettre au format européen, sur les VRAIES
 * factures.
 *
 * ── POURQUOI UNE COMMANDE ET PAS UN TEST ─────────────────────────────────────────────────────────
 *
 * Un test dit que le contrôle marche. Il ne dit pas où en est le produit. Cette commande répond à
 * une question qu'on se pose devant un client — *« peut-on lui facturer ? »* — et elle y répond
 * avec les codes `BT-xx` qu'un comptable ou un intégrateur reconnaît.
 *
 * ⚠ **ELLE N'ÉCRIT RIEN, DONC ELLE NE SE PLANIFIE PAS.** Une commande qui imprime et qu'on
 * planifierait tournerait dans les journaux d'un conteneur que personne ne lit — c'est le piège
 * relevé le 31/08 sur `personnel:qualifications:verifier`. Celle-ci se lance à la main, quand on se
 * pose la question.
 */
#[AsCommand(
    name: 'facturation:einvoicing:etat',
    description: 'Ce qui manque pour émettre les factures au format européen EN 16931.',
)]
final class EinvoicingReadinessCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceReadiness $readiness,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factures = $this->em->getRepository(Facture::class)->findAll();

        // ⚠ ZÉRO FACTURE N'EST PAS « TOUT VA BIEN ». Sans ce garde, la commande dirait « aucun
        // manque » sur une base vide, et on lirait ça comme un feu vert.
        if ($factures === []) {
            $output->writeln('');
            $output->writeln('  Aucune facture en base : ce rapport ne mesure rien.');
            $output->writeln('  Un « aucun manque » sur zéro facture n’est pas un feu vert.');
            $output->writeln('');

            return Command::SUCCESS;
        }

        $parTerme = [];
        $emettables = 0;
        $emises = 0;

        foreach ($factures as $facture) {
            \assert($facture instanceof Facture);
            if ($facture->getStatut() !== StatutFacture::Brouillon) {
                ++$emises;
            }
            $manques = $this->readiness->manques($facture);
            if ($manques === []) {
                ++$emettables;
                continue;
            }
            foreach ($manques as $m) {
                $cle = $m['terme']->value;
                $parTerme[$cle] ??= ['terme' => $m['terme'], 'ou' => $m['ou'], 'factures' => 0];
                ++$parTerme[$cle]['factures'];
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '  %d facture(s) en base, dont %d émise(s) · %d émettable(s) au format européen',
            \count($factures),
            $emises,
            $emettables,
        ));
        $output->writeln('');

        if ($parTerme === []) {
            $output->writeln('  Aucun terme obligatoire ne manque.');
            $output->writeln('');
            $output->writeln('  ⚠ Ça ne veut PAS dire « conforme ». Ce contrôle vérifie la PRÉSENCE des');
            $output->writeln('    termes ; il ne vérifie aucune règle métier de la norme (BR-xx) ni les');
            $output->writeln('    restrictions nationales. La conformité se prouve contre un schematron.');
            $output->writeln('');

            return Command::SUCCESS;
        }

        // Le vendeur d'abord : c'est le blocage structurel, et il se corrige une fois pour toutes.
        foreach ([true, false] as $vendeur) {
            $lot = array_filter($parTerme, static fn (array $t) => $t['terme']->concerneLeVendeur() === $vendeur);
            if ($lot === []) {
                continue;
            }

            $output->writeln($vendeur
                ? '  ── VENDEUR — non renseigné, donc AUCUNE facture n’est émettable ────────────'
                : '  ── AUTRES TERMES ──────────────────────────────────────────────────────────');
            $output->writeln('');

            foreach ($lot as $t) {
                // ⚠ `sprintf('%-46s')` COMPTE DES OCTETS, pas des caractères. « l'unité de
                // mesure » porte plus d'octets que de lettres, et les colonnes se décalaient
                // d'autant — un tableau désaligné se lit mal, et un rapport mal lu ne sert pas.
                $output->writeln(sprintf(
                    '    %s %s %s',
                    mb_str_pad($t['terme']->value, 7),
                    mb_str_pad($t['terme']->libelle(), 46),
                    $t['ou'],
                ));
            }
            $output->writeln('');
        }

        // ⚠ CETTE PHRASE A CHANGÉ AVEC LE MODÈLE, ET C'ÉTAIT LE PIÈGE.
        //
        // Elle disait « manque de DONNÉE, aucun connecteur ne le comblera » — vrai le 31/08 au
        // matin, quand les champs n'existaient pas. La migration `Version20260831180000` les a
        // créés : ce qui manque désormais est une SAISIE, pas un modèle. Les deux ne se corrigent
        // pas au même endroit, et confondre les deux fait chercher un développeur là où il faut un
        // gestionnaire.
        $output->writeln('  Les champs existent (migration du 31/08) : ce qui manque ici est une SAISIE.');
        $output->writeln('  ⚠ Aucun raccordement à Chorus, à une PDP, à VeriFactu ou à SdI ne remplacera');
        $output->writeln('    une identité de vendeur non renseignée.');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
