<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Nf525\Alert\Nf525AlertOpenedListener;
use App\Platform\Nf525\Alert\OpenedAlert;
use App\Platform\Nf525\SealChainRef;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * LA CLOCHE D'UNE ALERTE NF525, ÉCRITE DANS LA TRANSACTION DE L'ALERTE (plan, D-D).
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - Les lignes sont écrites en SQL sur la SECONDE connexion, pas par l'ORM : la transaction principale
 *   s'annule avec le refus, celle de l'alerte non. Cloche ⇔ alerte ouverte, exactement une fois (le Store
 *   n'appelle ce listener qu'à l'ouverture). Une exception ici annule l'alerte entière.
 * - Les destinataires se résolvent pour chaque établissement VISÉ (liaisons de l'alerte), jamais pour
 *   l'établissement actif de l'appelant. La résolution est une LECTURE sur la connexion principale.
 * - Mêmes colonnes et mêmes valeurs que `NotifyOnDomainEvent` ; un test compare aux métadonnées ORM de
 *   `Notification` pour qu'une colonne ajoutée à l'entité fasse échouer ce test, pas la production.
 */
final class Nf525AlertNotifier implements Nf525AlertOpenedListener
{
    public function __construct(private readonly NotificationRecipientResolver $recipients)
    {
    }

    public function onOpened(Connection $alertConnection, OpenedAlert $alert, SealChainRef $chain, array $establishmentIds): void
    {
        $rule = NotificationRule::forEvent('nf525.chain_blocked');
        if ($rule === null) {
            throw new \LogicException('Règle de notification nf525.chain_blocked absente.');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $title = $rule->title . ' — ' . $chain->label();
        $params = json_encode([$rule->paramName => $alert->alertId], \JSON_THROW_ON_ERROR);

        // Dédoublonné : [A, A] ne doit pas sonner deux fois chez le même destinataire.
        $unique = [];
        foreach ($establishmentIds as $establishmentId) {
            $unique[$establishmentId->toRfc4122()] = $establishmentId;
        }

        foreach ($unique as $establishmentId) {
            foreach ($this->recipients->resolve($establishmentId, $rule->module, $rule->action) as $recipient) {
                $alertConnection->executeStatement(
                    'INSERT INTO platform_notification
                        (id, destinataire_id, etablissement_id, horodatage, gravite, titre, texte, ecran, params, source, lue)
                     VALUES (:id, :recipient, :establishment, :at, :severity, :title, :text, :screen, :params, :source, 0)',
                    [
                        'id' => Uuid::v4()->toBinary(),
                        'recipient' => $recipient->getId()->toBinary(),
                        'establishment' => $establishmentId->toBinary(),
                        'at' => $now,
                        'severity' => $rule->severity->value,
                        'title' => mb_substr($title, 0, 160),
                        'text' => $rule->text,
                        'screen' => $rule->screen,
                        'params' => $params,
                        'source' => $rule->eventName,
                    ],
                );
            }
        }
    }
}
