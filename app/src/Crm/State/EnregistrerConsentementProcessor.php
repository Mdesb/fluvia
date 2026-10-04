<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /clients/{id}/consentements (RG-M4-07/10, CA-16/CA-18) : insère une nouvelle ligne
 * **append-only** (accord, révocation, renouvellement). Corps :
 * { "canal": "email"|"sms"|"courrier", "etat": "accorde"|"refuse"|…, "source": "…",
 *   "dateExpiration"?: "AAAA-MM-JJ", "recueilliParRepresentant"?: bool }.
 * Un mineur requiert `recueilliParRepresentant=true` pour un état `accorde` (RG-M4-10).
 *
 * @implements ProcessorInterface<Client, Consentement>
 */
final class EnregistrerConsentementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Consentement
    {
        \assert($data instanceof Client);

        $corps = $this->lecteur->corps();
        $canal = CanalConsentement::tryFrom(\is_string($corps['canal'] ?? null) ? $corps['canal'] : '');
        $etat = EtatConsentement::tryFrom(\is_string($corps['etat'] ?? null) ? $corps['etat'] : '');
        if ($canal === null || $etat === null) {
            throw new UnprocessableEntityHttpException('« canal » et « etat » sont requis et doivent être valides.');
        }
        if ($etat === EtatConsentement::Invalide) {
            // Une invalidation porte un motif et un lot, posés par une reprise (#101). Saisie à la main,
            // elle n'aurait ni l'un ni l'autre : ce serait un « refusé » qui ne dit pas son nom.
            throw new UnprocessableEntityHttpException('L\'état « invalide » est réservé aux reprises ; saisissez « refuse » pour un refus.');
        }

        $recueilliParRepresentant = ($corps['recueilliParRepresentant'] ?? false) === true;
        if ($data->estMineur() && $etat === EtatConsentement::Accorde && !$recueilliParRepresentant) {
            throw new UnprocessableEntityHttpException('Un consentement accordé pour un mineur requiert le recueil par le représentant légal (RG-M4-10).');
        }

        $consentement = new Consentement($canal, $etat);
        $consentement->setClient($data);
        $consentement->setSource(\is_string($corps['source'] ?? null) ? $corps['source'] : 'inconnue');
        $consentement->setRecueilliParRepresentant($recueilliParRepresentant);
        if (isset($corps['dateExpiration']) && \is_string($corps['dateExpiration'])) {
            $consentement->setDateExpiration(new \DateTimeImmutable($corps['dateExpiration']));
        }

        $this->em->persist($consentement);
        $this->em->flush();

        return $consentement;
    }
}
