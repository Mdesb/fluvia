<?php

declare(strict_types=1);

namespace App\Boutique\Command;

use App\Boutique\Entity\Vitrine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ÉCRIT LA LIGNE NGINX QUI DIT QUI A LE DROIT D'ENCADRER CHAQUE BOUTIQUE.
 *
 * **Le problème que ça règle.** Sans en-tête `frame-ancestors`, n'importe quel site peut afficher la
 * boutique d'un client dans une iframe — sous son propre nom, sur son propre domaine. Le visiteur
 * paie sur une page qu'il croit être celle du site encadrant. Rien ne casse, rien n'alerte : c'est
 * exactement pourquoi personne ne le remarque.
 *
 * **Pourquoi une commande et pas un en-tête posé par PHP.** L'iframe encadre le **front statique**
 * (`/b/<slug>`), pas l'API. C'est nginx qui sert ces octets, et lui seul peut poser l'en-tête sur
 * cette réponse. PHP ne voit jamais passer cette requête.
 *
 * **Pourquoi une carte par boutique, et pas une liste unique.** Une seule ligne globale contenant
 * l'union des domaines autorisés laisserait le site du client A encadrer la boutique du client B.
 * Entre deux commerces d'une même ville, c'est une confusion de marque ; entre un commerce et un
 * concurrent, c'est pire. La carte nginx associe **chaque boutique à ses seuls domaines**.
 *
 * > **Autoriser tout le monde partout, c'est n'autoriser personne nulle part en particulier.**
 *
 * **Fermé par défaut.** Une boutique qui n'a déclaré aucun domaine reçoit `'none'` : elle n'est
 * encadrable nulle part. C'est le sens sûr de l'erreur — une boutique qu'on n'arrive pas à intégrer
 * se signale tout de suite et se corrige en une ligne ; une boutique encadrable par n'importe qui ne
 * se signale jamais.
 *
 * **Cette commande ne touche pas à nginx** : elle écrit sur la sortie standard ce qu'il faut y coller.
 * Modifier la configuration d'un serveur est une décision d'exploitation, pas un effet de bord d'une
 * commande de génération.
 *
 * Usage :
 *   php bin/console app:integration:csp
 *   php bin/console app:integration:csp --file=/etc/nginx/snippets/fluvia-frame-ancestors.conf
 */
#[AsCommand(
    name: 'app:integration:csp',
    description: 'Génère la carte nginx frame-ancestors à partir des domaines déclarés sur chaque vitrine.',
)]
final class BuildFrameAncestorsMap extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Écrire le résultat dans ce fichier au lieu de la sortie standard.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<Vitrine> $storefronts */
        $storefronts = $this->em->getRepository(Vitrine::class)->findAll();

        $rows = [];
        $openCount = 0;
        foreach ($storefronts as $storefront) {
            $slug = $storefront->getSlug();
            $origins = $storefront->getDomainesIntegration();
            if ($slug === null || $slug === '' || $origins === []) {
                continue;
            }
            ++$openCount;
            $rows[] = sprintf(
                '    "~^/b/%s(/|$)"%s"%s";',
                preg_quote($slug, '/'),
                str_repeat(' ', max(2, 34 - \strlen($slug))),
                implode(' ', $origins),
            );
        }

        $map = self::HEADER . "map \$uri \$fluvia_frame_ancestors {\n"
            . "    default" . str_repeat(' ', 33) . "\"'none'\";\n"
            . ($rows === [] ? '' : implode("\n", $rows) . "\n")
            . "}\n";

        $path = $input->getOption('file');
        if (\is_string($path) && $path !== '') {
            file_put_contents($path, $map);
            $output->writeln(sprintf('Carte écrite dans %s (%d boutique(s) intégrable(s) sur %d).', $path, $openCount, \count($storefronts)));

            return Command::SUCCESS;
        }

        $output->write($map);
        $output->writeln('');
        $output->writeln(sprintf(
            '<comment>%d boutique(s) intégrable(s) sur %d. Les autres restent non encadrables (\'none\').</comment>',
            $openCount,
            \count($storefronts),
        ));

        return Command::SUCCESS;
    }

    private const HEADER = <<<'TXT'
        # Généré par `php bin/console app:integration:csp` — ne pas modifier à la main.
        #
        # À placer dans le contexte http (hors server), puis, DANS le server qui sert la boutique :
        #
        #     add_header Content-Security-Policy "frame-ancestors $fluvia_frame_ancestors" always;
        #
        # `always` est indispensable : sans lui, nginx omet l'en-tête sur les réponses d'erreur — et
        # une page 404 encadrée reste une page encadrée.
        #
        # Une boutique absente de cette carte n'est encadrable nulle part : c'est voulu. Pour en
        # ouvrir une, déclarez ses domaines sur la fiche vitrine, puis régénérez ce fichier.

        TXT;
}
