<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\TypeSessionClient;
use App\Boutique\Identite\FournisseurIdentiteInterface;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Security\TentativeIdentificationLimiter;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use App\Boutique\Service\PanierTarificationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * POST /boutique/paniers/{id}/identifier — étape 1 du tunnel (US-L8-04, RG-M3-06, CA-4). Quatre
 * voies : client déjà connecté (`session`, #101), compte existant (e-mail + mot de passe), invité
 * (achat simple sans compte, e-mail obligatoire depuis #101), FranceConnect (bouchon, derrière
 * `FRANCECONNECT_BOUCHON_AUTORISE`). Corps :
 * { "mode": "session"|"compte"|"invite"|"franceconnect", "email"?, "motDePasse"?, "franceConnectCode"? }.
 *
 * @implements ProcessorInterface<PanierEnLigne, PanierEnLigne>
 */
final class IdentifierPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierTarificationHandler $tarification,
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly FournisseurIdentiteInterface $fournisseurIdentite,
        private readonly TentativeIdentificationLimiter $limiter,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $corps = $this->lecteur->corps();
        $mode = \is_string($corps['mode'] ?? null) ? $corps['mode'] : 'invite';

        match ($mode) {
            'session' => $this->identifierParSession($data),
            'compte' => $this->identifierParCompte($data, $corps),
            'franceconnect' => $this->identifierParFranceConnect($data, $corps),
            default => $this->identifierInvite($data, $corps),
        };

        $this->em->flush();

        // ⚠ LE PANIER REPART AVEC SES PRIX. Sans cette ligne, la réponse d'une mutation ne
        // porte ni `total` ni `prixUnitaire` — seul `PanierAvecTotalProvider` (le GET) enrichit —
        // et le frontal, qui garde cette réponse en état, affichait un panier sans aucun montant
        // jusqu'au prochain rechargement. Mesuré à l'écran : « Total 8,00 € » disparaissait au
        // premier clic sur « − », remplacé par « le montant total sera calculé à l'étape de
        // paiement », qui se lit comme une politique et non comme un raté.
        // `calculer()` est pur : les trois champs sont transitoires, sans `#[ORM\Column]`.
        $this->tarification->calculer($data);

        return $data;
    }

    /** @param array<string, mixed> $corps */
    private function identifierParCompte(PanierEnLigne $panier, array $corps): void
    {
        $email = \is_string($corps['email'] ?? null) ? $corps['email'] : '';
        $motDePasse = \is_string($corps['motDePasse'] ?? null) ? $corps['motDePasse'] : '';

        // Revue de sécurité — faille majeure (anti-bruteforce) : ce mode valide un mot de passe hors
        // firewall Symfony Security (aucun throttling natif applicable ici) — cf.
        // `TentativeIdentificationLimiter` : fenetre glissante du composant `symfony/rate-limiter`.
        $this->limiter->verifierAvantTentative($email);

        $utilisateur = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if (!$utilisateur instanceof Utilisateur || !$this->hasher->isPasswordValid($utilisateur, $motDePasse)) {
            $this->limiter->enregistrerEchec($email);
            throw new UnauthorizedHttpException('', 'Identifiants invalides.');
        }
        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);
        if (!$compte instanceof CompteClient) {
            throw new UnprocessableEntityHttpException('Ce compte ne porte pas d\'espace boutique.');
        }

        $this->limiter->reinitialiser($email);
        $panier->setCompteClient($compte)->setContactConnu($email);
    }

    /**
     * Le client est déjà connecté (JWT client) : on rattache le panier à SON compte, sans lui
     * redemander ni e-mail ni mot de passe (#101). Sans ce mode, un client connecté passait en
     * invité, et sa commande n'apparaissait jamais dans « Mes billets ».
     *
     * ⚠ SEULEMENT DANS LE GROUPE DE LA VITRINE. Un compte d'un autre groupe qui rattache un panier
     * d'ici ferait entrer chez lui la commande d'un exploitant étranger. On rend 404, pas 403 : « ce
     * compte n'existe pas pour cette boutique » est la vérité vue d'ici, et un 403 confirmerait à un
     * tiers que le compte existe ailleurs.
     */
    private function identifierParSession(PanierEnLigne $panier): void
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnauthorizedHttpException('Bearer', 'Connectez-vous pour acheter avec votre compte.');
        }

        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);
        $groupeCompte = $compte?->getEtablissement()?->getRegion()?->getGroupe()?->getId();
        $groupeVitrine = $panier->getVitrine()?->getEtablissement()?->getRegion()?->getGroupe()?->getId();
        if (!$compte instanceof CompteClient || $groupeCompte === null || $groupeVitrine === null || !$groupeCompte->equals($groupeVitrine)) {
            throw new NotFoundHttpException('Aucun compte client pour cette boutique.');
        }

        $panier->setCompteClient($compte)->setContactConnu($utilisateur->getEmail());
    }

    /**
     * Sans compte, l'e-mail est OBLIGATOIRE (#101, décision du 04/10) : c'est par lui qu'on envoie
     * les billets et qu'on retrouve la commande. Refus 422, comme l'écran.
     *
     * @param array<string, mixed> $corps
     */
    private function identifierInvite(PanierEnLigne $panier, array $corps): void
    {
        $email = \is_string($corps['email'] ?? null) ? trim($corps['email']) : '';
        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new UnprocessableEntityHttpException('Une adresse e-mail valide est requise pour recevoir vos billets.');
        }

        $panier->setContactConnu($email);
        $session = $panier->getSessionClient();
        $session?->setContactEmail($email);
    }

    /** @param array<string, mixed> $corps */
    private function identifierParFranceConnect(PanierEnLigne $panier, array $corps): void
    {
        $code = \is_string($corps['franceConnectCode'] ?? null) ? $corps['franceConnectCode'] : '';
        $identite = $this->fournisseurIdentite->authentifier($code, '');

        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['franceConnectId' => $identite->sub]);
        if ($compte instanceof CompteClient) {
            $panier->setCompteClient($compte)->setContactConnu($identite->email);

            return;
        }

        // RG-M3-06 : identifié sans compte imposé — session FranceConnect en cours, aucun CompteClient
        // créé tant que l'achat ne requiert pas explicitement un compte (RG-M3-12/17, §4.9).
        $panier->setContactConnu($identite->email);
        $session = $panier->getSessionClient();
        if ($session !== null) {
            $session->setType(TypeSessionClient::FranceconnectEnCours)->setContactEmail($identite->email);
        }
    }
}
