<?php

declare(strict_types=1);

namespace App\Crm\Notification;

use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Aucune notification ne part sans consentement, et ce n'est pas une vérification : c'est un passage
 * obligé.
 *
 * **Pourquoi ici et pas chez l'appelant.** D42 (RG-CMP-06) exige que le consentement soit *impossible
 * à contourner*, et non *vérifié*. Un contrôle placé dans l'écran, dans la constitution de l'audience
 * ou dans chaque module appelant serait oublié une fois — et une fois suffit, parce que la sanction
 * n'est pas un test rouge mais une plainte. Ici il n'y a qu'un chemin vers le client
 * (`ClientNotifierInterface`), et ce décorateur est dessus.
 *
 * **Pourquoi dans `Crm` et pas dans `Platform`.** Le consentement appartient à `Crm`. Faire connaître
 * `Consentement` au noyau inverserait la dépendance (D3/D8) : le noyau doit ignorer les modules.
 * `Platform` porte le contrat, `Crm` porte la règle — chacun ce qu'il possède.
 *
 * **Échec fermé, y compris sur l'absence.** `accorde` sur le canal visé, et non expiré, passe. Tout le
 * reste — `refuse`, `a_renouveler`, `invalide`, consentement absent, client introuvable — est **refusé**. L'absence
 * de consentement n'est pas un « peut-être » : c'est un non.
 *
 * ⚠ **Le chemin CONTRACTUEL ne vérifie pas que le destinataire existe** — mesuré le 07/09. Il sort
 * avant la recherche du client, donc un identifiant erroné y passe sans être vu, et c'est le
 * notifieur en dessous qui en hérite. Ce n'est pas corrigé ici : y ajouter un contrôle changerait la
 * politique sur un chemin qui conditionne des prélèvements SEPA réels (`DebitPreNotifier` refuse de
 * prélever si le préavis n'est pas parti). Zone sensible au sens de CLAUDE.md — arbitrage, pas
 * correctif glissé en passant.
 *
 * **`Refusee` n'est pas une exception.** Un refus est un cas normal et fréquent, qui doit se compter et
 * s'afficher (RG-CMP-08). Lever ici obligerait chaque appelant à attraper, et on sait comment finissent
 * les exceptions qu'on attrape en boucle.
 */
#[AsDecorator(ClientNotifierInterface::class)]
final readonly class ConsentGatedNotifier implements ClientNotifierInterface
{
    public function __construct(
        private ClientNotifierInterface $decorated,
        private EntityManagerInterface $entityManager,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function notify(ClientNotification $notification): NotificationOutcome
    {
        if ($notification->basis === NotificationBasis::Contractuelle) {
            // Un message nécessaire à l'exécution du contrat — facture, confirmation, accès ouvert —
            // n'a pas besoin d'un consentement MARKETING, et le refuser priverait le client de ce
            // qu'il a acheté. La base légale est déclarée par l'appelant et vaut `Consentement` par
            // défaut : personne ne tombe ici par distraction.
            return $this->decorated->notify($notification);
        }

        $client = $this->entityManager->getRepository(Client::class)->find($notification->clientId);

        if (!$client instanceof Client) {
            // ⚠ MÊME VERDICT, MAIS PLUS LE MÊME SILENCE.
            //
            // Refuser d'écrire à quelqu'un qui a dit non est un résultat métier normal. Refuser
            // parce que l'appelant a passé un identifiant qui ne désigne AUCUN client est un défaut
            // de cet appelant — et il ne se voyait nulle part, puisque les deux rendaient `Refusee`.
            //
            // C'est ce silence qui a laissé passer deux défauts de Smart Flow : un identifiant de
            // bénéficiaire y était passé pour un identifiant de client, et la trace disait
            // « consentement ». La politique d'échec fermé ne change pas ; seule l'absence de trace
            // est corrigée.
            $this->logger?->warning('crm.notification.destinataire_inconnu', [
                'clientId' => (string) $notification->clientId,
                'source' => $notification->source,
                'modele' => $notification->templateKey,
                'consequence' => 'message non envoyé — refusé comme une absence de consentement',
            ]);

            return NotificationOutcome::Refusee;
        }

        $canal = CanalConsentement::tryFrom($notification->channel->value);

        if ($canal === null) {
            // Un canal de notification sans équivalent côté consentement ne peut pas être consenti,
            // donc il ne peut pas être utilisé. Les deux énumérations sont alignées à dessein ; si
            // elles divergent un jour, c'est ici qu'on s'en aperçoit, et du bon côté.
            return NotificationOutcome::Refusee;
        }

        // ⚠ LA LIGNE LA PLUS RÉCENTE, PAS LA PREMIÈRE VENUE. `Consentement` est append-only : l'état
        // courant d'un canal est sa DERNIÈRE ligne (`ConsentementResolver`). Sans ordre, `findOneBy`
        // rendait celle que la base voulait bien, sans garantie que ce soit la dernière : un accord
        // suivi d'un refus, ou d'une invalidation (#101), pouvait laisser passer les campagnes. Même ordre que le résolveur, pour que l'écran et l'envoi disent pareil.
        $consentement = $this->entityManager->getRepository(Consentement::class)
            ->findOneBy(['client' => $client, 'canal' => $canal], ['dateRecueil' => 'DESC']);

        if (!$consentement instanceof Consentement) {
            return NotificationOutcome::Refusee;
        }

        if ($consentement->getEtat() !== EtatConsentement::Accorde) {
            return NotificationOutcome::Refusee;
        }

        $expiration = $consentement->getDateExpiration();

        if ($expiration !== null && $expiration <= $notification->occurredAt) {
            // Un consentement expiré vaut un consentement absent. On compare à l'instant MÉTIER de la
            // notification et non à « maintenant » : une notification datée d'un fait passé ne doit pas
            // profiter d'un consentement recueilli depuis.
            return NotificationOutcome::Refusee;
        }

        return $this->decorated->notify($notification);
    }
}
