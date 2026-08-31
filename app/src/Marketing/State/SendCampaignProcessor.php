<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\Campaign;
use App\Marketing\Enum\CampaignStatus;
use App\Marketing\Service\CampaignSender;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /marketing/campagnes/{id}/envoyer` — le geste qu'on ne rattrape pas.
 *
 * **L'autorité est recalculée contre l'établissement de la CAMPAGNE.** L'attribut `security` de
 * l'opération contrôle `campagne.gerer` sur l'établissement actif, c'est-à-dire sur l'en-tête
 * `X-Etablissement` — un sélecteur envoyé par le client, pas une preuve d'appartenance (D6). Sans ce
 * second contrôle, quelqu'un pourrait déclencher l'envoi d'une campagne d'un autre établissement.
 *
 * Le refus est un **404** : distinguer « hors périmètre » de « inexistante » permettrait d'énumérer
 * les campagnes du voisin en essayant des identifiants.
 *
 * **Une campagne déjà envoyée ne se rejoue pas.** Le refus est un 409, pas un envoi silencieux :
 * rejouer recontacterait des gens qui ont déjà reçu le message. C'est la façon la plus simple de
 * brûler un canal, et un double-clic suffirait.
 *
 * @implements ProcessorInterface<Campaign, JsonResponse>
 */
final readonly class SendCampaignProcessor implements ProcessorInterface
{
    public function __construct(
        private CampaignSender $sender,
        private Security $security,
        private CalculateurDroits $calculateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        if (!$data instanceof Campaign) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $data->getEstablishment()?->getId());
        if (!$this->calculateur->autorise($codes, 'campagne', 'gerer')) {
            // Même message que « inexistante » : un refus distinct confirmerait son existence.
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        if ($data->getStatus() === CampaignStatus::Envoyee) {
            throw new ConflictHttpException(
                'Cette campagne est déjà partie. Une campagne ne se rejoue pas : ceux qui l’ont reçue '
                . 'la recevraient deux fois. Créez-en une nouvelle.',
            );
        }

        $comptes = $this->sender->envoyer($data);

        return new JsonResponse([
            'campagne' => $data->getLabel(),
            'statut' => $data->getStatus()->value,
            // Le détail par issue, jamais un total : « 310 exclus faute de consentement » dit qu'il
            // faut travailler le recueil du consentement, « 930 envoyés » ne dit rien.
            'resultat' => $comptes,
            'envoiReelDisponible' => false,
        ]);
    }
}
