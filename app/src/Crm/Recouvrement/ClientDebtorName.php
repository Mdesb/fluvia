<?php

declare(strict_types=1);

namespace App\Crm\Recouvrement;

use App\Crm\Entity\Client;
use App\Recouvrement\Port\DebtorNamePort;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le nom d'un redevable de type `crm.client` — celui que produit TOUT rejet SEPA.
 *
 * `DeclarerRejetSepaProcessor::TYPE_REDEVABLE_CLIENT` vaut `crm.client`, et la référence est l'UUID
 * du client du mandat. C'est donc le type le plus fréquent à l'écran Recouvrement, et le seul qui
 * n'avait aucun module pour le nommer.
 *
 * ⚠ CE PORT NE SAIT QUE NOMMER. Il n'implémente pas `RedevablePort` : une implémentation de celui-là
 * fournirait aussi `estLieA()`, que `RedevableSoiVoter` consulte, et changerait donc qui voit quoi.
 * Afficher un nom ne doit pas ouvrir une porte.
 */
final class ClientDebtorName implements DebtorNamePort
{
    public const TYPE = 'crm.client';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function debtorType(): string
    {
        return self::TYPE;
    }

    public function debtorName(string $debtorReference): ?string
    {
        if (!Uuid::isValid($debtorReference)) {
            return null;
        }

        $client = $this->em->getRepository(Client::class)->find(Uuid::fromString($debtorReference));

        return $client instanceof Client ? self::nameOf($client) : null;
    }

    /**
     * ⚠ LA PERSONNE MORALE D'ABORD. Un client peut porter une raison sociale ET un nom de contact ;
     * la facture et le mandat sont au nom de la société. Rendre « Jean Dupont » là où le prélèvement
     * dit « SARL Dupont » enverrait chercher la mauvaise ligne au relevé.
     */
    public static function nameOf(Client $client): ?string
    {
        $raisonSociale = trim((string) $client->getRaisonSociale());
        if ($raisonSociale !== '') {
            return $raisonSociale;
        }

        $nom = trim(implode(' ', array_filter([$client->getPrenom(), $client->getNom()])));

        return $nom !== '' ? $nom : null;
    }
}
