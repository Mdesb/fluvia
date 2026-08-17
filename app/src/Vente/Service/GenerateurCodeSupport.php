<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Enum\TypeSupport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Génère et vérifie le **code de support unique et signé** (payload QR/RFID) émis pour chaque support
 * d'accès (billet, carte, abonnement, billet boutique — CA-12). Format opaque, imprimable/scannable :
 *
 *   {PREFIXE}-{ALEA}-{SIGNATURE}   ex. « BIL-K7QRJXTN2VH4PLQS-9F3A2B7C1D »
 *
 * - PREFIXE (3 lettres) : type de support (BIL billet, CAR carte, QRC qr générique, BRA bracelet,
 *   WAL wallet) — distinct du préfixe legacy « QR- » (2 lettres) utilisé par les identifiants
 *   manuels/historiques (fixtures, RFID de démonstration) pour ne jamais les confondre avec un code
 *   signé (`estCodeSigne()` renvoie false dessus).
 * - ALEA (16 caractères, alphabet Crockford sans lettres ambiguës I/L/O/U) : ~80 bits d'entropie
 *   (`random_int`, CSPRNG), garantit l'unicité pratique du code (retry applicatif en cas de collision
 *   improbable, cf. `App\Vente\Service\ValiderVenteService::creerSupport()`).
 * - SIGNATURE (10 caractères hex) : HMAC-SHA256 tronqué du couple préfixe+aléa, calculé avec la clé
 *   applicative `SUPPORT_HMAC_KEY` (env, secret/vault en production — même patron que
 *   `MFA_ENCRYPTION_KEY`/`SEPA_IBAN_KEY`). Permet de vérifier qu'un code scanné a bien été émis par ce
 *   système (non forgeable sans la clé) — indépendamment de sa présence en base, ce qui autorise un
 *   contrôle de premier niveau y compris hors-ligne (RG-ACC-07).
 *
 * ⚠ Ce n'est **pas** un chiffrement : le code reste opaque mais ne contient aucune donnée métier — sa
 * seule fonction est l'authenticité (HMAC), pas la confidentialité. La clé HMAC n'est jamais exposée
 * (aucun getter, jamais sérialisée).
 */
final class GenerateurCodeSupport
{
    private const ALPHABET_ALEA = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford (sans I, L, O, U).
    private const LONGUEUR_ALEA = 16;
    private const LONGUEUR_SIGNATURE = 10;
    private const REGEX = '/^(?<prefixe>[A-Z]{3})-(?<alea>[0-9A-HJKMNP-TV-Z]{' . self::LONGUEUR_ALEA . '})-(?<signature>[0-9A-F]{' . self::LONGUEUR_SIGNATURE . '})$/';

    public function __construct(
        #[Autowire(env: 'SUPPORT_HMAC_KEY')] private readonly string $cle,
    ) {
    }

    /** Génère un nouveau code signé pour le type de support donné. */
    public function genererPourType(TypeSupport $type): string
    {
        $charge = $this->prefixe($type) . '-' . $this->genererAlea();

        return $charge . '-' . $this->signer($charge);
    }

    /** Vrai si `$code` respecte le format d'un code émis par ce générateur (indépendamment de la signature). */
    public function estCodeSigne(string $code): bool
    {
        return preg_match(self::REGEX, $code) === 1;
    }

    /**
     * Vérifie l'authenticité de `$code` : format valide **et** signature HMAC correcte. Un code dont
     * le format ne correspond pas (identifiant legacy/manuel, RFID libre…) est considéré non signé —
     * ce n'est pas en soi une fraude, cf. `estCodeSigne()`. Utiliser cette méthode pour détecter un
     * code sciemment forgé/altéré (format correct mais signature invalide).
     */
    public function verifier(string $code): bool
    {
        if (preg_match(self::REGEX, $code, $correspondances) !== 1) {
            return false;
        }

        $charge = $correspondances['prefixe'] . '-' . $correspondances['alea'];
        $attendue = $this->signer($charge);

        return hash_equals($attendue, strtoupper($correspondances['signature']));
    }

    private function signer(string $charge): string
    {
        return strtoupper(substr(hash_hmac('sha256', $charge, $this->cle), 0, self::LONGUEUR_SIGNATURE));
    }

    private function genererAlea(): string
    {
        $longueurAlphabet = \strlen(self::ALPHABET_ALEA);
        $alea = '';
        for ($i = 0; $i < self::LONGUEUR_ALEA; ++$i) {
            $alea .= self::ALPHABET_ALEA[random_int(0, $longueurAlphabet - 1)];
        }

        return $alea;
    }

    private function prefixe(TypeSupport $type): string
    {
        return match ($type) {
            TypeSupport::Billet => 'BIL',
            TypeSupport::Carte => 'CAR',
            TypeSupport::Qr => 'QRC',
            TypeSupport::Bracelet => 'BRA',
            TypeSupport::Wallet => 'WAL',
        };
    }
}
