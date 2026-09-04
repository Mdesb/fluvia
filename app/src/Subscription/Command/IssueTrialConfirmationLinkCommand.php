<?php

declare(strict_types=1);

namespace App\Subscription\Command;

use App\Crm\Entity\Client;
use App\Subscription\Entity\Subscription;
use App\Subscription\Service\TrialConfirmationLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Rend le lien de confirmation d'un essai, pour éprouver le tunnel tant qu'aucun courriel ne part.
 *
 * ⚠ **CETTE COMMANDE CONTOURNE LA SEULE GARDE DU TUNNEL D'ESSAI. C'EST TOUT SON OBJET, ET C'EST
 * POURQUOI ELLE S'ANNONCE AINSI.**
 *
 * L'essai de 14 jours ne demande **aucun paiement**. Ce qui empêche n'importe qui de créer des
 * établissements réels en boucle — groupe, région, compte administrateur — n'est donc pas un mandat
 * SEPA : c'est le clic sur un lien envoyé à une adresse dont on vérifie ainsi qu'elle existe et
 * qu'elle appartient au demandeur. Cette commande frappe ce lien sans passer par l'adresse. Entre
 * les mains de quelqu'un qui a accès au serveur, elle vaut « confirmer n'importe quelle demande ».
 *
 * **POURQUOI ELLE EXISTE QUAND MÊME (E-8, arbitré par Maxime le 04/09).** Aucun courriel ne sort de
 * la plateforme : `MAILER_DSN=null://null` et `ClientNotifierInterface` est câblé sur
 * `LogClientNotifier`, qui ne journalise **ni le contenu ni les variables**. Le lien n'existe donc
 * nulle part après l'envoi — ni en base, qui ne garde que le `sha256`, ni dans le journal. Sans cet
 * outil, le tunnel d'inscription ne peut être déroulé jusqu'au bout **par personne**, y compris par
 * son propre éditeur : ce n'est pas seulement l'ouverture au public qui est bloquée, c'est la
 * recette.
 *
 * ---
 *
 * **LE VERROU N'EST PAS L'ENVIRONNEMENT, ET LE DÉPÔT AVAIT DÉJÀ TRANCHÉ CE CAS.** L'idiome pour
 * interdire un outil dangereux est ailleurs de le refuser hors `dev`/`test` — voir
 * {@see \App\Platform\DataFixtures\PurgeurInterditHorsDeveloppement}. Il ne convient pas ici : la
 * préproduction tourne en `APP_ENV=prod`, et c'est précisément là qu'on veut éprouver le tunnel. Un
 * refus sur l'environnement fermerait l'outil au seul endroit qui en a besoin.
 *
 * C'est exactement le raisonnement déjà écrit pour `SEPA_TRANSMISSION_SIMULEE` dans `app/.env`, et
 * on suit cet idiome plutôt que d'en inventer un second : un drapeau explicite, dont le défaut est
 * le REFUS, pour qu'une installation qui l'oublie échoue **fermée**.
 *
 * Le verrou est donc une **autorisation explicite** : `TRIAL_CONFIRMATION_BYPASS=1`. Absente par
 * défaut, elle doit rester absente en production. Le nom dit ce qu'elle ouvre — « bypass », pas
 * « debug » ni « test » — pour qu'on ne la pose pas par distraction, et pour qu'elle se remarque
 * dans un fichier d'environnement.
 *
 * ⚠ **CE VERROU N'EST PAS UNE SÉCURITÉ, C'EST UNE INTENTION.** Qui peut poser la variable peut
 * lancer la commande. Il empêche l'accident, pas l'abus. La vraie fermeture de ce contournement est
 * la levée d'E-8 : le jour où un expéditeur réel est branché, cette commande n'a plus de raison
 * d'être et doit être retirée.
 */
#[AsCommand(
    name: 'app:trial:confirmation-link',
    description: 'Rend le lien de confirmation d\'un essai (recette du tunnel, tant qu\'aucun courriel ne part).',
)]
final class IssueTrialConfirmationLinkCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TrialConfirmationLink $liens,
        #[Autowire(env: 'bool:TRIAL_CONFIRMATION_BYPASS')] private readonly bool $autorisee = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'demande',
            InputArgument::REQUIRED,
            'L\'identifiant de l\'abonnement, ou l\'adresse électronique du prospect.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->autorisee) {
            $io->error([
                'Commande fermée.',
                'Elle frappe un lien de confirmation sans passer par l\'adresse du prospect, ce qui'
                    ." contourne la seule garde de l'essai gratuit — il n'y a aucun paiement dans ce"
                    .' parcours, le clic sur le lien est ce qui remplace le mandat.',
                'Pour l\'ouvrir en recette : TRIAL_CONFIRMATION_BYPASS=1. Jamais en production.',
            ]);

            return Command::FAILURE;
        }

        $demande = (string) $input->getArgument('demande');
        $subscription = $this->trouver($demande);

        if (!$subscription instanceof Subscription) {
            $io->error(sprintf('Aucune demande d\'essai ne correspond à « %s ».', $demande));

            return Command::FAILURE;
        }

        // ⚠ ON LE DIT AVANT DE FRAPPER. Une demande déjà confirmée n'a pas besoin d'un lien neuf, et
        // en frapper un donnerait l'illusion qu'il reste quelque chose à faire.
        if (null !== $subscription->getEmailConfirmedAt()) {
            $io->warning(sprintf(
                'Demande déjà confirmée le %s : l\'essai court, aucun lien n\'est nécessaire.',
                $subscription->getEmailConfirmedAt()->format('d/m/Y H:i'),
            ));

            return Command::SUCCESS;
        }

        $lien = $this->liens->issue($subscription);
        $expiration = $this->liens->expiresAt($subscription);
        $maintenant = new \DateTimeImmutable();

        $io->section('Lien de confirmation');
        $io->writeln($lien);
        $io->newLine();

        // ⚠ LA VALIDITÉ SE COMPTE DEPUIS LA CRÉATION DE LA DEMANDE, PAS DEPUIS CETTE FRAPPE.
        //
        // Frapper un jeton neuf ne rouvre donc PAS la fenêtre. Sans cet avertissement, l'outil rend
        // un lien d'apparence normale qui répondra « lien expiré », et on chercherait le défaut dans
        // le tunnel plutôt que dans l'âge de la demande.
        if ($maintenant > $expiration) {
            $io->warning([
                sprintf(
                    'Ce lien est DÉJÀ EXPIRÉ : la demande date du %s et la fenêtre s\'est fermée le %s.',
                    $subscription->getCreatedAt()->format('d/m/Y H:i'),
                    $expiration->format('d/m/Y H:i'),
                ),
                'La validité se compte depuis la création de la demande, pas depuis la frappe du'
                    .' jeton : un jeton neuf ne rouvre pas la fenêtre. Pour une recette, repartez'
                    .' d\'une demande fraîche.',
            ]);

            return Command::SUCCESS;
        }

        $io->success(sprintf('Valable jusqu\'au %s.', $expiration->format('d/m/Y H:i')));

        return Command::SUCCESS;
    }

    /**
     * Par identifiant d'abonnement, ou par l'adresse du prospect — c'est celle-ci qu'on a sous la
     * main quand on vient de remplir le formulaire.
     */
    private function trouver(string $demande): ?Subscription
    {
        if (Uuid::isValid($demande)) {
            $parId = $this->em->getRepository(Subscription::class)->find(Uuid::fromString($demande));
            if ($parId instanceof Subscription) {
                return $parId;
            }
        }

        $prospect = $this->em->getRepository(Client::class)->findOneBy(['email' => $demande]);
        if (!$prospect instanceof Client) {
            return null;
        }

        // La plus récente : un prospect qui s'y reprend à deux fois attend le lien de sa DERNIÈRE
        // demande, pas de la première.
        return $this->em->getRepository(Subscription::class)->findOneBy(
            ['customerReference' => $prospect->getId()->toRfc4122()],
            ['createdAt' => 'DESC'],
        );
    }
}
