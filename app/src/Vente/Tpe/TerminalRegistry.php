<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Caisse\Entity\PointDeVente;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Le connecteur d'un point de vente (`PointDeVente.tpe.connecteur`, spec TPE lot 1 §6), et celui
 * d'une tentative déjà envoyée, par son nom.
 *
 * Sans connecteur déclaré, le terminal EXTERNE : un point de vente ne demande rien à configurer pour
 * encaisser une carte. Ce défaut est un paramètre (`app.tpe.connecteur_par_defaut`), pas une variable
 * d'environnement ; seul l'environnement de test le pose sur le terminal simulé, que les tests de la
 * caisse exercent depuis toujours.
 *
 * Les connecteurs des lots suivants (`caisse_ap_pont`, `nepting`, `stripe`) n'existent pas encore : un
 * point de vente qui les déclarerait — la validation l'empêche — reçoit un 422, rien n'est envoyé.
 */
final class TerminalRegistry
{
    /** Ce que `PointDeVente.tpe.connecteur` peut valoir, et ce qui existe aujourd'hui. */
    public const KNOWN = ['externe', 'simule', 'caisse_ap_pont', 'nepting', 'stripe'];
    public const AVAILABLE = ['externe', 'simule'];

    public function __construct(
        // L'alias du port : le terminal simulé — décoré en test (`BlockingTerminal`), voir services.yaml.
        #[Autowire(service: TerminalPaiementInterface::class)]
        private readonly TerminalPaiementInterface $simulated,
        private readonly ExternalTerminal $external,
        #[Autowire('%app.tpe.connecteur_par_defaut%')]
        private readonly string $defaultConnector = ExternalTerminal::CONNECTOR,
    ) {
    }

    public function forPointOfSale(PointDeVente $pointDeVente): TerminalPaiementInterface
    {
        $reglage = $pointDeVente->getTpe();
        $nom = \is_array($reglage) && \is_string($reglage['connecteur'] ?? null) ? $reglage['connecteur'] : $this->defaultConnector;

        return $this->byName($nom) ?? throw new UnprocessableEntityHttpException(sprintf(
            'Le terminal de paiement de ce point de vente (« %s ») n\'est pas disponible : la carte n\'a pas été débitée. Encaissez par un autre moyen.',
            $nom,
        ));
    }

    /** Le connecteur de ce nom ; nul s'il n'existe pas (ou pour une tentative d'avant les connecteurs). */
    public function byName(?string $nom): ?TerminalPaiementInterface
    {
        return match ($nom) {
            ExternalTerminal::CONNECTOR => $this->external,
            TpeMock::CONNECTOR => $this->simulated,
            default => null,
        };
    }
}
