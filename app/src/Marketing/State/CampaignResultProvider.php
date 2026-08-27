<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Marketing\Entity\Campaign;
use App\Marketing\Entity\CampaignRecipient;
use App\Marketing\Enum\ExclusionReason;
use App\Marketing\Enum\RecipientOutcome;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /marketing/campagnes/{id}/resultat` — ce qui s'est réellement passé.
 *
 * **Le détail par motif, jamais un total.** « 930 envoyés » ne dit rien ; « 310 exclus faute de
 * consentement » dit qu'il faut travailler le recueil du consentement avant d'écrire un message de
 * plus (RG-CMP-08). C'est souvent l'information la plus utile de tout l'écran, et c'est celle qu'un
 * chiffre global efface.
 *
 * **Tout se recompte depuis le journal figé, rien n'est recopié.** Un compteur tenu sur la campagne
 * se désynchroniserait au premier incident d'envoi — et un compteur faux se lit exactement comme un
 * compteur juste.
 *
 * > **Un chiffre qu'on peut compter ne se recopie pas.**
 *
 * L'autorité est recalculée contre l'établissement de la campagne, et le refus est un 404 : l'en-tête
 * `X-Etablissement` est un sélecteur, pas une preuve (D6).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class CampaignResultProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private CalculateurDroits $calculateur,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        $campagne = $id !== null
            ? $this->entityManager->getRepository(Campaign::class)->find($id)
            : null;

        if (!$campagne instanceof Campaign) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $campagne->getEstablishment()?->getId());
        if (!$this->calculateur->autorise($codes, 'campagne', 'lire')) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        /** @var list<CampaignRecipient> $destinataires */
        $destinataires = $this->entityManager->getRepository(CampaignRecipient::class)
            ->findBy(['campaign' => $campagne]);

        $contactes = 0;
        $temoins = 0;
        $exclus = [];

        foreach ($destinataires as $destinataire) {
            match ($destinataire->getOutcome()) {
                RecipientOutcome::Envoye, RecipientOutcome::Journalise => $contactes++,
                RecipientOutcome::Temoin => $temoins++,
                RecipientOutcome::Exclu => null,
            };

            $motif = $destinataire->getExclusionReason();
            if ($motif instanceof ExclusionReason) {
                $exclus[$motif->value] = [
                    'motif' => $motif->label(),
                    'nombre' => ($exclus[$motif->value]['nombre'] ?? 0) + 1,
                ];
            }
        }

        return new JsonResponse([
            'campagne' => $campagne->getLabel(),
            'statut' => $campagne->getStatus()->value,
            'envoyeeLe' => $campagne->getSentAt()?->format(\DATE_ATOM),
            'cibles' => \count($destinataires),
            'contactes' => $contactes,
            // Le témoin est affiché à part, jamais parmi les exclus : ce n'est pas un raté, c'est
            // la seule chose qui permettra de dire si la campagne a servi à quelque chose.
            'temoins' => $temoins,
            'exclus' => array_values($exclus),
            'fenetreAttributionJours' => $campagne->getAttributionWindowDays(),
            'envoiReelDisponible' => false,
        ]);
    }
}
