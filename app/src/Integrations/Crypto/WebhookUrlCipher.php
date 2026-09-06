<?php

declare(strict_types=1);

namespace App\Integrations\Crypto;

use App\Securite\Crypto\ChiffreurSecret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Coffre des URL de webhook sortantes.
 *
 * **Réutilise** `ChiffreurSecret` (libsodium `crypto_secretbox`, nonce par message) plutôt que de
 * poser un cinquième mécanisme : il y en a déjà quatre de la même forme — secret MFA, IBAN SEPA,
 * clé d'API OCR, jeton social — et un chiffrement de plus est une surface de plus à auditer pour
 * zéro gain.
 *
 * **Clé dédiée** : `INTEGRATIONS_WEBHOOK_KEY`, distincte des quatre autres. Compromettre ce coffre
 * ne doit rien donner sur le reste.
 *
 * ⚠ **PAS DE MACHINERIE DE ROTATION ICI, ET C'EST UN CHOIX QUI SE JUSTIFIE.** Le coffre social en
 * porte une (clés retirées, commande de rechiffrement) parce qu'un jeton perdu oblige chaque
 * établissement à repasser le parcours d'autorisation de chaque réseau — un coût qu'on ne peut pas
 * demander. Une URL de webhook, elle, se recolle en trente secondes depuis Slack. Si la clé venait
 * à changer, la conséquence est que chaque destination redevient à saisir, et l'écran le dira :
 * `dernierEchec` portera la raison. Poser une rotation qui ne servira jamais coûterait plus qu'elle
 * n'épargne.
 *
 * ⚠ **CE COFFRE NE JOURNALISE RIEN.** Une URL ne sort d'ici que vers le client HTTP qui l'appelle —
 * ni dans une réponse d'API, ni dans un événement, ni dans un journal. Le jour où un message
 * d'erreur la recopie, elle devient lisible par quiconque lit les journaux.
 */
final class WebhookUrlCipher
{
    private readonly ChiffreurSecret $chiffreur;

    public function __construct(
        #[Autowire(env: 'INTEGRATIONS_WEBHOOK_KEY')]
        string $cleBase64,
    ) {
        $this->chiffreur = new ChiffreurSecret($cleBase64);
    }

    public function chiffrer(string $url): string
    {
        return $this->chiffreur->chiffrer($url);
    }

    /**
     * @return string|null `null` quand la valeur est illisible — clé changée, ligne corrompue.
     *                     On rend `null` plutôt que de lever : un envoi qui échoue se voit dans
     *                     `dernierEchec` ; une exception ici ferait tomber l'abonné, donc tous les
     *                     envois des autres destinations du même événement.
     */
    public function dechiffrer(string $chiffree): ?string
    {
        if ($chiffree === '') {
            return null;
        }

        try {
            return $this->chiffreur->dechiffrer($chiffree);
        } catch (\Throwable) {
            return null;
        }
    }
}
