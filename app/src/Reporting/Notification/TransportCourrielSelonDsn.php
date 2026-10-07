<?php

declare(strict_types=1);

namespace App\Reporting\Notification;

/**
 * Décide si le transport est réel en lisant `MAILER_DSN`, et rien d'autre.
 *
 * ── CE QUI EST MESURÉ, ET CE QUI NE L'EST PAS ───────────────────────────────────────────────────
 *
 * Mesuré : le schéma du DSN configuré. `null://` avale tout en silence ; c'est le seul transport
 * fourni par Symfony dont le contrat soit « ne rien faire, sans erreur ».
 *
 * PAS mesuré : qu'un transport réel fonctionne. Un `smtp://` vers un serveur éteint est « réel » au
 * sens de cette classe, et lèvera une exception à l'envoi — que `ExecuterRapportsCommand` attrape
 * déjà et enregistre en `Echec`. La distinction est donc bien placée : ici on sépare « rien ne
 * partira, jamais » de « ça part ou ça échoue bruyamment ».
 *
 * ⚠ ON NE DEVINE PAS DEPUIS L'ENVIRONNEMENT. Il serait tentant d'écrire « en test, rien ne part » :
 * ce serait faux dès qu'un environnement de test reçoit un vrai DSN, et ça masquerait le cas
 * inverse — une PRÉPRODUCTION avec un transport nul, qui est précisément la situation réelle du
 * 15/09/2026. Le DSN est le fait ; `APP_ENV` n'est qu'une corrélation.
 */
final class TransportCourrielSelonDsn implements TransportCourrielInterface
{
    /**
     * Schémas dont le contrat est de ne rien expédier.
     *
     * `null://` est celui de Symfony. On ne liste pas `smtp`, `sendmail` ou les passerelles : tout
     * ce qui n'est pas ici est traité comme réel, ce qui est le repli prudent — un faux « réel »
     * produit une exception visible, un faux « nul » produirait un rapport jamais envoyé que
     * personne ne réclamerait.
     */
    private const SCHEMAS_MUETS = ['null'];

    public function __construct(
        private readonly string $mailerDsn,
    ) {
    }

    public function estReel(): bool
    {
        return $this->raison() === null;
    }

    public function raison(): ?string
    {
        $dsn = trim($this->mailerDsn);

        if ($dsn === '') {
            return 'Aucun transport de courriel n\'est configuré (`MAILER_DSN` est vide).';
        }

        $schema = strtolower((string) parse_url($dsn, PHP_URL_SCHEME));
        // `parse_url` rend `null` sur une chaîne sans « :// » ; on retombe alors sur la partie qui
        // précède le premier « : », pour ne pas déclarer réel un DSN mal formé.
        if ($schema === '') {
            $schema = strtolower(strtok($dsn, ':') ?: '');
        }

        if (\in_array($schema, self::SCHEMAS_MUETS, true)) {
            return sprintf(
                'Le transport de courriel configuré est `%s://` : il accepte les messages et n\'en '
                . 'expédie aucun.',
                $schema,
            );
        }

        return null;
    }
}
