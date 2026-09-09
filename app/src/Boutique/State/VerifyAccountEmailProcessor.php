<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Service\GuestOrderClaimHandler;
use App\Securite\Entity\EmailVerificationToken;
use App\Securite\Security\PublicEndpointRateLimiter;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/comptes/verifier-email — le clic depuis le courriel.
 *
 * Corps : { "jeton": "<le clair recu par courriel>" }.
 *
 * ⚠ UNE SEULE REPONSE POUR TOUS LES REFUS, ET C'EST DELIBERE. Jeton inconnu, deja utilise, expire :
 * un message different par cas dirait a qui essaie des jetons au hasard lequel a EXISTE. On rend le
 * meme 422 dans les trois cas. Le confort de diagnostic irait ici a l'attaquant, pas au client --
 * lui n'essaie qu'un jeton, le sien, et il vient de le recevoir.
 *
 * ⚠ ET ON LIMITE LE DEBIT. Sans cela, la route devient un banc d'essai a jetons : 64 caracteres
 * hexadecimaux ne se devinent pas, mais une route publique sans frein finit toujours par servir a
 * autre chose. On reutilise le limiteur de reinitialisation, meme forme de secret a usage unique.
 *
 * @cloisonnement-verifie : il n'y a PAS de session ici, donc aucun perimetre a recalculer -- la
 * route est publique par necessite, on clique depuis sa boite mail. L'autorite est le JETON :
 * 32 octets aleatoires, stockes en SHA-256, a usage unique et expirant sous 48 h. Rien n'est
 * resolu depuis un identifiant fourni par le client : l'utilisateur vient du jeton, le compte
 * vient de l'utilisateur, et le rattachement qui suit est borne a `compte->getEtablissement()`.
 * Aucun chemin n'atteint donc l'entite d'un autre etablissement sans detenir son jeton -- et
 * detenir le jeton EST la preuve. Pose le 07/09/2026 apres lecture de
 * `bin/garde-fou-cloisonnement.php`.
 *
 * ⚠ LE RATTACHEMENT NE FAIT PAS ECHOUER LA VERIFICATION. Si rendre ses commandes au compte echoue,
 * l'adresse reste verifiee : ce sont deux effets, et le second est un bonus. Les lier ferait qu'un
 * defaut de l'historique bloquerait l'activation du compte.
 */
final class VerifyAccountEmailProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GuestOrderClaimHandler $rattachement,
        private readonly PublicEndpointRateLimiter $limiter,
        private readonly RequestStack $requests,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $this->limiter->assertPasswordResetAllowed($this->requests->getCurrentRequest()?->getClientIp());

        $corps = $this->lecteur->corps();
        $clair = \is_string($corps['jeton'] ?? null) ? trim($corps['jeton']) : '';
        if ($clair === '') {
            throw new UnprocessableEntityHttpException('Lien de confirmation invalide ou expire.');
        }

        $jeton = $this->em->getRepository(EmailVerificationToken::class)
            ->findOneBy(['jeton' => hash('sha256', $clair)]);

        // Le meme message pour « inconnu », « deja utilise » et « expire » — voir l'en-tete.
        if (!$jeton instanceof EmailVerificationToken || !$jeton->estValide(new \DateTimeImmutable())) {
            throw new UnprocessableEntityHttpException('Lien de confirmation invalide ou expire.');
        }

        $utilisateur = $jeton->getUtilisateur();
        $compte = $utilisateur === null
            ? null
            : $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);

        if (!$compte instanceof CompteClient) {
            throw new UnprocessableEntityHttpException('Lien de confirmation invalide ou expire.');
        }

        // ⚠ ON CONSOMME LE JETON AVANT DE RATTACHER. Un rattachement long, rejoue par un double clic,
        // repasserait sinon deux fois sur les memes commandes ; le marquer d'abord rend le second
        // passage sans effet.
        $jeton->marquerUtilise();
        $compte->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        $rendues = $this->rattachement->rattacher($compte, $jeton->getAdresse());

        return new JsonResponse([
            'verifie' => true,
            'commandesRattachees' => $rendues,
        ]);
    }
}
