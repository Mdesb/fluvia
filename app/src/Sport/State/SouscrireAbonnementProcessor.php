<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Service\SouscriptionAbonnementHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sport/abonnements/souscrire (US-SPORT-01, CA-1). Corps :
 *   { "adherent": iri|uuid, "payeur": iri|uuid, "formule": iri|uuid, "periodicite": "mensuel"|"hebdomadaire",
 *     "dateSouscription"?: "AAAA-MM-JJ", "dureeEngagementMois": int, "montantCentimes": int,
 *     "iban": string, "titulaireMandat": string }
 * L'IBAN en clair transite uniquement ici (jamais mappé Doctrine, tokenisé avant persistance, §4 du plan).
 *
 * @implements ProcessorInterface<mixed, AbonnementFitness>
 */
final class SouscrireAbonnementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly SouscriptionAbonnementHandler $handler,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AbonnementFitness
    {
        $corps = $this->lecteur->corps();

        $adherent = $this->resoudre(Beneficiaire::class, $corps['adherent'] ?? null);
        $payeur = $this->resoudre(Client::class, $corps['payeur'] ?? null);
        $formule = $this->resoudre(Formule::class, $corps['formule'] ?? null);
        if (!$adherent instanceof Beneficiaire || !$payeur instanceof Client || !$formule instanceof Formule) {
            throw new UnprocessableEntityHttpException('« adherent », « payeur » et « formule » sont requis et doivent référencer des ressources existantes.');
        }

        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $periodicite = PeriodiciteAbonnementFitness::tryFrom(\is_string($corps['periodicite'] ?? null) ? $corps['periodicite'] : '');
        if ($periodicite === null) {
            throw new UnprocessableEntityHttpException('« periodicite » invalide (mensuel|hebdomadaire).');
        }

        $iban = \is_string($corps['iban'] ?? null) ? $corps['iban'] : '';
        $titulaire = \is_string($corps['titulaireMandat'] ?? null) ? $corps['titulaireMandat'] : '';
        if (trim($iban) === '' || trim($titulaire) === '') {
            throw new UnprocessableEntityHttpException('« iban » et « titulaireMandat » sont requis pour signer le mandat SEPA.');
        }

        $dateSouscription = isset($corps['dateSouscription']) && \is_string($corps['dateSouscription'])
            ? new \DateTimeImmutable($corps['dateSouscription'])
            : new \DateTimeImmutable('today');
        $dureeEngagementMois = isset($corps['dureeEngagementMois']) ? (int) $corps['dureeEngagementMois'] : 12;
        $montantCentimes = isset($corps['montantCentimes']) ? (int) $corps['montantCentimes'] : 0;
        if ($montantCentimes <= 0) {
            throw new UnprocessableEntityHttpException('« montantCentimes » doit être strictement positif.');
        }

        return $this->handler->souscrire(
            $adherent,
            $payeur,
            $formule,
            $etablissement,
            $periodicite,
            $dateSouscription,
            $dureeEngagementMois,
            $montantCentimes,
            $iban,
            $titulaire,
        );
    }

    /** @param class-string $classe */
    private function resoudre(string $classe, mixed $reference): ?object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            return null;
        }

        return $this->em->getRepository($classe)->find($uuid);
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
