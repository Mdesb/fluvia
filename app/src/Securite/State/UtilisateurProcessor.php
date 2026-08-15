<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Securite\Notification\InvitationMailer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hache le mot de passe fourni en clair (RG-SOCLE-06) avant la persistance, puis délègue
 * au processor Doctrine standard. Le hash n'est jamais renvoyé (aucun groupe de lecture).
 *
 * RG-M8-01 (US-L7-03, CA-1) : si aucun mot de passe n'est fourni en écriture (cas standard d'un
 * admin créant un compte), génère un jeton d'invitation à durée limitée (haché en base), force
 * `statut = invite` et déclenche l'envoi d'un e-mail. Si un mot de passe EST fourni (fixtures/
 * tests/migration de données existants), comportement legacy conservé (`statut = actif`
 * directement) — zéro régression sur les fixtures socle/CRM qui persistent directement via
 * Doctrine (donc hors processor de toute façon) ni sur d'éventuels appels API existants.
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class UtilisateurProcessor implements ProcessorInterface
{
    public const DUREE_INVITATION_HEURES = 72;

    /**
     * @param ProcessorInterface<Utilisateur, Utilisateur> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly InvitationMailer $invitationMailer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Utilisateur) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $creation = $operation instanceof Post;
        $motDePasseFourni = $data->getMotDePasseClair() !== null && $data->getMotDePasseClair() !== '';

        if ($motDePasseFourni) {
            $data->setMotDePasse($this->hasher->hashPassword($data, $data->getMotDePasseClair()));
            $data->eraseCredentials();
            if ($creation) {
                $data->setStatut(StatutUtilisateur::Actif);
            }
        } elseif ($creation) {
            $jetonClair = $this->genererJetonInvitation($data);
            $resultat = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
            $this->invitationMailer->envoyer($data, $jetonClair);

            return $resultat;
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function genererJetonInvitation(Utilisateur $utilisateur): string
    {
        $jetonClair = bin2hex(random_bytes(32));
        $utilisateur->setJetonInvitation(hash('sha256', $jetonClair));
        $utilisateur->setJetonInvitationExpire(new \DateTimeImmutable('+' . self::DUREE_INVITATION_HEURES . ' hours'));
        $utilisateur->setStatut(StatutUtilisateur::Invite);
        // Mot de passe technique inconnu tant que non activé : hash aléatoire inutilisable.
        $utilisateur->setMotDePasse($this->hasher->hashPassword($utilisateur, bin2hex(random_bytes(32))));

        return $jetonClair;
    }
}
