<?php

declare(strict_types=1);

namespace App\Integrations\Enum;

/**
 * Le service au bout de l'URL. Il ne change pas ce qu'on envoie — il change la FORME du message.
 *
 * Slack, Teams et Discord acceptent tous les trois une URL de webhook entrante et un corps JSON,
 * sans authentification à négocier ni jeton à renouveler : c'est ce qui permet à UN SEUL émetteur
 * de couvrir les trois. Mais leurs corps diffèrent — `text` pour Slack et Discord, `TextBlock`
 * dans une carte adaptative pour Teams — et envoyer le mauvais donne un message vide plutôt qu'une
 * erreur.
 *
 * ⚠ `Generique` EST LE CAS HONNÊTE, PAS UN REPLI PARESSEUX. Il envoie l'événement tel quel, sans
 * habillage : c'est ce qu'attend un automate maison, un n8n ou un Zapier. Prétendre habiller pour
 * un destinataire qu'on ne connaît pas produirait un message que personne ne sait lire.
 */
enum EndpointKind: string
{
    case Slack = 'slack';
    case Teams = 'teams';
    case Discord = 'discord';
    case Generique = 'generique';

    public function libelle(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
            self::Discord => 'Discord',
            self::Generique => 'Webhook générique',
        };
    }

    /**
     * L'hôte attendu, quand le service en impose un.
     *
     * Sert à PRÉVENIR, jamais à refuser : une URL Slack collée dans une destination Teams part
     * quand même, et c'est voulu — les proxys d'entreprise et les relais internes réécrivent les
     * hôtes, et refuser sur ce critère rendrait le module inutilisable là où il sert le plus.
     */
    public function hoteAttendu(): ?string
    {
        return match ($this) {
            self::Slack => 'hooks.slack.com',
            self::Discord => 'discord.com',
            self::Teams => null,
            self::Generique => null,
        };
    }
}
