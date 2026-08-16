<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Musee\Entity\DossierGroupeScolaire;
use App\Musee\Entity\ParametreMuseeEtablissement;
use App\Reservation\Entity\Creneau;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Crée un dossier groupe/scolaire (POST /musee/dossiers-groupe, US-MUSEE-06) : `dateOption` défaut =
 * aujourd'hui + `ParametreMuseeEtablissement.delaiOptionDossierGroupeJours` si non fournie (§4.6).
 * Corps : { "etablissementScolaire", "effectif", "accompagnateurs"?, "creneauEntree", "dateOption"? }.
 *
 * @implements ProcessorInterface<mixed, DossierGroupeScolaire>
 */
final class CreerDossierGroupeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DossierGroupeScolaire
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $etablissementScolaire = \is_string($corps['etablissementScolaire'] ?? null) ? $corps['etablissementScolaire'] : '';
        if ($etablissementScolaire === '') {
            throw new UnprocessableEntityHttpException('Champ « etablissementScolaire » obligatoire.');
        }
        $effectif = isset($corps['effectif']) ? max(1, (int) $corps['effectif']) : 0;
        if ($effectif < 1) {
            throw new UnprocessableEntityHttpException('Champ « effectif » obligatoire (≥ 1).');
        }
        $accompagnateurs = isset($corps['accompagnateurs']) ? max(0, (int) $corps['accompagnateurs']) : 0;

        $creneau = $this->resoudre(Creneau::class, $corps['creneauEntree'] ?? null, 'creneauEntree');
        \assert($creneau instanceof Creneau);

        $dateOption = null;
        if (isset($corps['dateOption']) && \is_string($corps['dateOption']) && $corps['dateOption'] !== '') {
            $dateOption = new \DateTimeImmutable($corps['dateOption']);
        } else {
            $parametre = $this->em->getRepository(ParametreMuseeEtablissement::class)->findOneBy(['etablissement' => $etablissement]);
            $delai = $parametre?->getDelaiOptionDossierGroupeJours() ?? 15;
            $dateOption = (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $delai));
        }

        $dossier = new DossierGroupeScolaire();
        $dossier->setEtablissementScolaire($etablissementScolaire)
            ->setEffectif($effectif)
            ->setAccompagnateurs($accompagnateurs)
            ->setCreneauEntree($creneau)
            ->setDateOption($dateOption)
            ->setEtablissement($etablissement);
        $this->em->persist($dossier);
        $this->em->flush();

        return $dossier;
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
