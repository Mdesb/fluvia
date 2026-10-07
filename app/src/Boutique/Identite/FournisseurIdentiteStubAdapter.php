<?php

declare(strict_types=1);

namespace App\Boutique\Identite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Adaptateur par défaut — stub déterministe (⚠ Risque n°4 du plan) : ne contacte aucun IdP réel.
 * Le "code" attendu est de la forme "sub|email|nom|prenom" (usage test/démo uniquement).
 *
 * ⚠ FERMÉ PAR DÉFAUT (#101). Ce bouchon identifie quiconque lui tend un code de la forme ci-dessus :
 * n'importe qui pouvait se faire passer pour n'importe quelle adresse, dans tous les environnements.
 * Il ne répond plus que si `FRANCECONNECT_BOUCHON_AUTORISE=1` (posé par `phpunit.dist.xml`) — même modèle
 * que `PAIEMENT_EN_LIGNE_BOUCHON_AUTORISE` (#104/#116). L'écran ne propose plus FranceConnect tant
 * qu'il n'est que ce bouchon.
 */
final class FournisseurIdentiteStubAdapter implements FournisseurIdentiteInterface
{
    public function __construct(
        // `default::` : variable absente = `null` = refus. Aucun fichier d'environnement n'a besoin de
        // la déclarer pour que le bouchon soit FERMÉ ; seul `phpunit.dist.xml` l'ouvre, pour les tests.
        #[Autowire(env: 'bool:default::FRANCECONNECT_BOUCHON_AUTORISE')] private readonly bool $autorise = false,
    ) {
    }

    public function urlAutorisation(string $redirectUri): string
    {
        $this->garantirBouchonAutorise();

        return 'https://franceconnect.example.test/api/v1/authorize?redirect_uri=' . urlencode($redirectUri);
    }

    public function authentifier(string $code, string $redirectUri): IdentiteFranceConnect
    {
        $this->garantirBouchonAutorise();

        $segments = explode('|', $code);
        if (\count($segments) < 4 || trim($segments[0]) === '') {
            throw new UnprocessableEntityHttpException('Code FranceConnect invalide ou expiré (stub).');
        }

        return new IdentiteFranceConnect(
            sub: $segments[0],
            email: $segments[1],
            nom: $segments[2],
            prenom: $segments[3],
            dateNaissance: isset($segments[4]) && $segments[4] !== '' ? new \DateTimeImmutable($segments[4]) : null,
        );
    }

    private function garantirBouchonAutorise(): void
    {
        if (!$this->autorise) {
            throw new AccessDeniedHttpException('FranceConnect n\'est pas disponible sur cette boutique.');
        }
    }
}
