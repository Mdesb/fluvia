<?php

declare(strict_types=1);

namespace App\Platform\Command;

use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Subscription\Service\ProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `platform:install` — rend utilisable une base de production VIERGE.
 *
 * **Ce qui manquait.** Après `doctrine:migrations:migrate`, une base neuve n'a aucun établissement et
 * aucun compte : personne ne peut se connecter, donc personne ne peut ouvrir le premier client. Le
 * seul code qui créait un éditeur et son administrateur était `SocleFixtures` — qui ne tourne qu'en
 * test et en démonstration, et qui pose le mot de passe `aaa`.
 *
 * **Elle ne compose aucune politique de droits**, pour la raison de {@see ProvisioningService} : elle
 * duplique le rôle modèle « Administrateur d'établissement », posé par une migration
 * (`Version20260824233000`, joker `*.*` + `securite.gerer`), et échoue bruyamment s'il manque. Le
 * joker porte sur les actions, pas sur les données : le cloisonnement reste celui de l'établissement.
 * `securite.gerer` rend le second facteur obligatoire à ce compte.
 *
 * **Aucun mot de passe n'est choisi ni écrit.** Le compte naît `invite`, avec un jeton d'activation
 * dont seule l'empreinte est en base — le mécanisme de toute invitation (`ActivationController`).
 * Le lien est IMPRIMÉ, pas envoyé : la production n'a pas encore de transport de courriel.
 *
 * **Rejouable.** Chaque élément est cherché avant d'être créé, et la sortie dit ce qui existait déjà.
 * Un second lancement ne crée rien ; `--new-link` réémet un lien si le premier a expiré.
 *
 * **Elle refuse une base qui a déjà des clients** — c'est-à-dire tout établissement autre que
 * l'éditeur. Lancée par erreur sur la préproduction, elle y planterait un second éditeur à côté de
 * celui que désigne `EDITOR_TENANT_ID`. `--force` lève ce refus, explicitement.
 */
#[AsCommand(
    name: 'platform:install',
    description: 'Installe une base vierge : établissement éditeur, administrateur invité, référentiels.',
)]
final class InstallPlatformCommand extends Command
{
    public const DEFAULT_EDITOR_NAME = 'Fluvia (éditeur)';

    private const INVITATION_HOURS = 72;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire(env: 'FRONT_BASE_URL')] private readonly string $frontBaseUrl,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Adresse du premier administrateur (obligatoire).')
            ->addOption('admin-name', null, InputOption::VALUE_REQUIRED, 'Nom affiché de l’administrateur.', 'Administrateur')
            ->addOption('editor-name', null, InputOption::VALUE_REQUIRED, 'Nom de l’établissement éditeur.', self::DEFAULT_EDITOR_NAME)
            ->addOption('new-link', null, InputOption::VALUE_NONE, 'Réémet un lien d’activation si l’administrateur n’a pas encore activé son compte (l’ancien lien cesse de fonctionner).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Installe même si la base contient déjà d’autres établissements. Geste explicite : sur une base existante, crée un éditeur de plus.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = trim((string) $input->getOption('admin-email'));
        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('--admin-email est obligatoire et doit être une adresse valide.');

            return Command::INVALID;
        }

        $front = rtrim($this->frontBaseUrl, '/');
        if ('prod' === $this->environment && ('' === $front || preg_match('#^https?://(localhost|127\.0\.0\.1)([:/]|$)#', $front))) {
            $io->error(sprintf(
                'FRONT_BASE_URL vaut « %s » : le lien d’activation pointerait vers la machine d’un développeur. Renseigne-la dans app/.env.local avant d’installer.',
                $front,
            ));

            return Command::FAILURE;
        }

        $editorName = trim((string) $input->getOption('editor-name'));
        $template = $this->em->getRepository(Role::class)->findOneBy([
            'nom' => ProvisioningService::ADMIN_ROLE_TEMPLATE,
            'estModele' => true,
        ]);
        if (!$template instanceof Role) {
            $io->error(sprintf(
                'Rôle modèle « %s » absent : les migrations ont-elles été jouées ? Cette commande ne compose pas de droits elle-même.',
                ProvisioningService::ADMIN_ROLE_TEMPLATE,
            ));

            return Command::FAILURE;
        }

        $editor = $this->em->getRepository(Etablissement::class)->findOneBy(['nom' => $editorName]);
        $others = \count($this->em->getRepository(Etablissement::class)->findAll()) - ($editor instanceof Etablissement ? 1 : 0);
        if ($others > 0 && !$input->getOption('force')) {
            $io->error(sprintf(
                'La base contient déjà %d établissement(s) autre(s) que « %s » : ce n’est pas une base vierge. Refus (--force pour passer outre).',
                $others,
                $editorName,
            ));

            return Command::FAILURE;
        }

        $done = [];
        $clearToken = null;

        $this->em->wrapInTransaction(function () use ($io, $input, $email, $editorName, $template, &$editor, &$done, &$clearToken): void {
            if ($editor instanceof Etablissement) {
                $done[] = ['Établissement éditeur', 'existait déjà'];
            } else {
                $groupe = (new Groupe())->setNom($editorName);
                $region = (new Region())->setNom($editorName)->setGroupe($groupe);
                $editor = (new Etablissement())->setNom($editorName)->setRegion($region)->setActif(true);
                $this->em->persist($groupe);
                $this->em->persist($region);
                $this->em->persist($editor);
                $done[] = ['Établissement éditeur', 'créé (avec son groupe et sa région)'];
            }

            $roleName = 'Administrateur — '.$editorName;
            $role = $this->em->getRepository(Role::class)->findOneBy(['nom' => $roleName]);
            if ($role instanceof Role) {
                $done[] = ['Rôle « '.$roleName.' »', 'existait déjà'];
            } else {
                $role = (new Role())->setNom($roleName)->setEstModele(false)->setRoleModeleOrigine($template);
                foreach ($template->getPermissions() as $permission) {
                    $role->addPermission($permission);
                }
                $this->em->persist($role);
                $done[] = ['Rôle « '.$roleName.' »', 'créé, copie du modèle « '.ProvisioningService::ADMIN_ROLE_TEMPLATE.' »'];
            }

            $admin = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
            if ($admin instanceof Utilisateur) {
                if (StatutUtilisateur::Invite === $admin->getStatut() && $input->getOption('new-link')) {
                    $clearToken = $this->invite($admin);
                    $done[] = ['Administrateur '.$email, 'existait déjà, nouveau lien d’activation émis'];
                } else {
                    $done[] = ['Administrateur '.$email, sprintf('existait déjà (statut « %s »)', $admin->getStatut()->value)];
                }
            } else {
                $admin = (new Utilisateur())
                    ->setEmail($email)
                    ->setNom(trim((string) $input->getOption('admin-name')) ?: $email)
                    ->setRolesSecurite(['ROLE_USER']);
                // Mot de passe technique que personne ne connaît, tant que le compte n'est pas activé.
                $admin->setMotDePasse($this->hasher->hashPassword($admin, bin2hex(random_bytes(32))));
                $clearToken = $this->invite($admin);
                $this->em->persist($admin);
                $done[] = ['Administrateur '.$email, 'créé, invité (aucun mot de passe utilisable)'];
            }

            $assignment = $this->em->getRepository(Affectation::class)->findOneBy([
                'utilisateur' => $admin,
                'role' => $role,
                'etablissement' => $editor,
            ]);
            if ($assignment instanceof Affectation) {
                $done[] = ['Affectation à l’éditeur', 'existait déjà'];
            } else {
                $this->em->persist((new Affectation())->setUtilisateur($admin)->setRole($role)->setEtablissement($editor));
                $done[] = ['Affectation à l’éditeur', 'créée'];
            }

            $this->em->flush();
        });

        // Le référentiel légal de TVA (France, articles du CGI) : sans lui, aucune facture ne propose de
        // taux. Idempotent par sa clé métier. Les blocs et métiers du site public sont semés par chaque
        // déploiement ; les options vendables ne le sont PAS ici : elles portent un prix provisoire,
        // qui serait mis en vente en production sans que personne l'ait décidé.
        $application = $this->getApplication();
        if (null !== $application && $application->has('vat:seed-legal-rates')) {
            $seed = $application->find('vat:seed-legal-rates');
            $code = $seed->run(new ArrayInput([]), $output);
            $done[] = ['Taux de TVA légaux', 0 === $code ? 'semés (vat:seed-legal-rates)' : 'ÉCHEC de vat:seed-legal-rates'];
        }

        $io->title('Installation de la plateforme');
        $io->table(['Élément', 'Résultat'], $done);

        \assert($editor instanceof Etablissement);
        $io->section('À poser dans app/.env.local');
        $io->writeln(sprintf('EDITOR_TENANT_ID=%s', $editor->getId()->toRfc4122()));

        if (null !== $clearToken) {
            $io->section(sprintf('Lien d’activation (valable %d heures, à transmettre à %s)', self::INVITATION_HOURS, $email));
            $io->writeln(sprintf('%s/activation?jeton=%s', $front, $clearToken));
        }

        $io->note('Non semé ici : les options vendables (subscription:options:seed) portent un prix provisoire — à lancer quand les prix sont décidés.');

        return Command::SUCCESS;
    }

    /** Pose un jeton d'activation ; seule son empreinte est écrite, la valeur claire est rendue. */
    private function invite(Utilisateur $admin): string
    {
        $token = bin2hex(random_bytes(32));
        $admin->setStatut(StatutUtilisateur::Invite)
            ->setJetonInvitation(hash('sha256', $token))
            ->setJetonInvitationExpire(new \DateTimeImmutable('+'.self::INVITATION_HOURS.' hours'));

        return $token;
    }
}
