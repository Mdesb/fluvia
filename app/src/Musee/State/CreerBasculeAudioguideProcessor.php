<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Musee\Entity\Audioguide;
use App\Musee\Entity\BasculeAudioguide;
use App\Musee\Entity\ParametreMuseeEtablissement;
use App\Musee\Entity\VisiteGuidee;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /musee/bascules-audioguide (US-MUSEE-04/11, CA-4/CA-11) : applique `tauxRemise` (paramètre
 * établissement par défaut, sauf override explicite). Corps : { "audioguide", "beneficiaire",
 * "visiteGuideeRefInitiale"?: iri|uuid, "tauxRemise"?: decimal }.
 *
 * @implements ProcessorInterface<mixed, BasculeAudioguide>
 */
final class CreerBasculeAudioguideProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BasculeAudioguide
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $audioguide = $this->resoudre(Audioguide::class, $corps['audioguide'] ?? null, 'audioguide');
        \assert($audioguide instanceof Audioguide);
        $beneficiaire = $this->resoudre(Beneficiaire::class, $corps['beneficiaire'] ?? null, 'beneficiaire');
        \assert($beneficiaire instanceof Beneficiaire);

        $visite = null;
        if (isset($corps['visiteGuideeRefInitiale'])) {
            $visite = $this->resoudre(VisiteGuidee::class, $corps['visiteGuideeRefInitiale'], 'visiteGuideeRefInitiale');
            \assert($visite instanceof VisiteGuidee);
        }

        $parametre = $this->em->getRepository(ParametreMuseeEtablissement::class)->findOneBy(['etablissement' => $etablissement]);
        $tauxDefaut = $parametre?->getTauxRemiseAudioguideDefaut() ?? '20.00';
        $tauxRemise = isset($corps['tauxRemise']) ? (string) $corps['tauxRemise'] : $tauxDefaut;

        $bascule = new BasculeAudioguide();
        $bascule->setAudioguide($audioguide)
            ->setBeneficiaire($beneficiaire)
            ->setVisiteGuideeRefInitiale($visite)
            ->setTauxRemise($tauxRemise)
            ->setEtablissement($etablissement);
        $this->em->persist($bascule);
        $this->em->flush();

        return $bascule;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
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
