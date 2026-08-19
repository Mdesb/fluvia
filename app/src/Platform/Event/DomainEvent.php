<?php

declare(strict_types=1);

namespace App\Platform\Event;

use App\Platform\Event\Exception\InvalidDomainEventException;

/**
 * L'enveloppe commune à tout événement du bus (RG-PLAT-01, catalogue §« Common envelope »).
 *
 * Immuable et validée à la construction : un événement mal formé n'existe pas, il ne se corrige pas
 * après coup. Une seule classe transporte **tous** les événements — le nom (`$name`) sert de clé
 * d'abonnement, pas la classe PHP. C'est la décision de conception centrale du lot : une classe par
 * événement obligerait l'abonné à importer le code de l'émetteur, ce que D2 interdit.
 *
 * @see EventBus pour la publication, EventName pour la règle de nommage.
 */
final class DomainEvent
{
    /**
     * Clés de charge utile refusées d'office (RG-PLAT-04).
     *
     * Un événement porte des **références** et le strict minimum. Ce garde-fou n'est pas une analyse
     * sémantique — il n'attrape que les fautes évidentes — mais il transforme l'invariant « jamais de
     * secret dans un événement » d'intention en règle exécutable, au moment où elle est encore facile
     * à respecter : aucun module n'émet encore.
     */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'password', 'passwd', 'secret', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'credential', 'credentials', 'private_key',
        'iban', 'bic', 'card_number', 'pan', 'cvv', 'cvc', 'ssn', 'nir',
    ];

    /** Profondeur maximale d'imbrication de la charge utile — au-delà, ce n'est plus une référence. */
    private const MAX_PAYLOAD_DEPTH = 8;

    public readonly EventName $name;
    public readonly \DateTimeImmutable $occurredAt;
    public readonly EventTenant $tenant;
    public readonly ?EventActor $actor;
    public readonly EventSubject $subject;

    /** @var array<string, scalar|array|null> */
    public readonly array $payload;

    /**
     * @param array<string, scalar|array|null> $payload identifiants + strict minimum (RG-PLAT-04)
     * @param ?EventActor                      $actor   `null` = le système (tâche planifiée, réaction en chaîne)
     * @param ?\DateTimeImmutable              $occurredAt normalisé en UTC ; `null` = maintenant
     */
    public function __construct(
        EventName|string $name,
        EventTenant $tenant,
        EventSubject $subject,
        array $payload = [],
        ?EventActor $actor = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        self::assertPayloadIsCarryable($payload);

        $this->name = $name instanceof EventName ? $name : new EventName($name);
        $this->tenant = $tenant;
        $this->subject = $subject;
        $this->payload = $payload;
        $this->actor = $actor;
        $this->occurredAt = ($occurredAt ?? new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /** L'enveloppe telle que décrite au catalogue — pour le journal, le débogage, un futur transport. */
    public function toArray(): array
    {
        return [
            'name' => $this->name->value,
            'occurredAt' => $this->occurredAt->format('Y-m-d\TH:i:s\Z'),
            'tenant' => ['establishmentId' => $this->tenant->establishmentId->toRfc4122()],
            'actor' => ['userId' => $this->actor?->userId->toRfc4122()],
            'subject' => ['type' => $this->subject->type, 'id' => $this->subject->id],
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function assertPayloadIsCarryable(array $payload, int $depth = 0, string $path = ''): void
    {
        if ($depth > self::MAX_PAYLOAD_DEPTH) {
            throw new InvalidDomainEventException(sprintf(
                'Charge utile trop imbriquée (%s niveaux) sous « %s » : un événement porte des '
                .'références, pas un document (RG-PLAT-04).',
                self::MAX_PAYLOAD_DEPTH,
                $path,
            ));
        }

        foreach ($payload as $key => $value) {
            $currentPath = '' === $path ? (string) $key : $path.'.'.$key;

            if (\is_string($key) && \in_array(strtolower($key), self::FORBIDDEN_PAYLOAD_KEYS, true)) {
                throw new InvalidDomainEventException(sprintf(
                    'Clé interdite dans la charge utile : « %s ». Un événement ne transporte ni secret '
                    .'ni donnée personnelle superflue (RG-PLAT-04) — passe une référence, l\'abonné '
                    .'relira la donnée dans son module s\'il y a droit.',
                    $currentPath,
                ));
            }

            if (\is_array($value)) {
                self::assertPayloadIsCarryable($value, $depth + 1, $currentPath);

                continue;
            }

            if (null !== $value && !\is_scalar($value)) {
                throw new InvalidDomainEventException(sprintf(
                    'Valeur non transportable sous « %s » (%s) : la charge utile n\'accepte que des '
                    .'scalaires, des tableaux et null. Un objet lierait l\'abonné au code de '
                    .'l\'émetteur (D2).',
                    $currentPath,
                    get_debug_type($value),
                ));
            }
        }
    }
}
