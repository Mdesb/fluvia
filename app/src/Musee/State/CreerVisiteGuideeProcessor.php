<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fonctionnalite\Enum\Metier;
use App\Musee\Entity\Guide;
use App\Musee\Entity\VisiteGuidee;
use App\Securite\Service\ContexteEtablissement;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Crée une visite guidée (POST /musee/visites-guidees, US-MUSEE-03) : cascade la `Ressource`
 * (`codeType='visite_guidee'`) et son `Creneau` dédiés (module socle Réservation **réutilisé**,
 * décision structurante n°1 du plan) — capacité **indépendante** de la jauge d'entrée de l'expo.
 *
 * Corps : { "theme", "langue", "pointRDV", "debut", "fin", "capacite", "guide"?: iri|uuid,
 *   "creneauEntree"?: iri|uuid }
 *
 * @implements ProcessorInterface<mixed, VisiteGuidee>
 */
final class CreerVisiteGuideeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VisiteGuidee
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $theme = \is_string($corps['theme'] ?? null) && $corps['theme'] !== '' ? $corps['theme'] : 'Visite guidée';
        $langue = \is_string($corps['langue'] ?? null) ? $corps['langue'] : '';
        if ($langue === '') {
            throw new UnprocessableEntityHttpException('Champ « langue » obligatoire.');
        }
        $pointRDV = \is_string($corps['pointRDV'] ?? null) ? $corps['pointRDV'] : '';
        $capacite = isset($corps['capacite']) ? max(1, (int) $corps['capacite']) : 10;

        $debut = $this->dateTime($corps['debut'] ?? null, 'debut');
        $fin = $this->dateTime($corps['fin'] ?? null, 'fin');
        if ($debut >= $fin) {
            throw new UnprocessableEntityHttpException('Le début de la visite doit être antérieur à la fin.');
        }

        $ressource = new Ressource();
        $ressource->setEtablissement($etablissement)
            ->setCodeType('visite_guidee')
            ->setVerticale(Metier::Musee->value)
            ->setLibelle($theme)
            ->setCapacitePropre($capacite);
        $this->em->persist($ressource);

        $creneauVisite = new Creneau();
        $creneauVisite->setRessource($ressource)
            ->setDebut($debut)
            ->setFin($fin)
            ->setCapacite($capacite)
            ->setEtablissement($etablissement)
            ->setStatut(StatutCreneau::Planifie);
        $this->em->persist($creneauVisite);

        $guide = null;
        if (isset($corps['guide'])) {
            $guide = $this->resoudre(Guide::class, $corps['guide'], 'guide');
            \assert($guide instanceof Guide);
        }

        $creneauEntree = null;
        if (isset($corps['creneauEntree'])) {
            $creneauEntree = $this->resoudre(Creneau::class, $corps['creneauEntree'], 'creneauEntree');
            \assert($creneauEntree instanceof Creneau);
        }

        $visite = new VisiteGuidee();
        $visite->setTheme($theme)
            ->setLangue($langue)
            ->setGuide($guide)
            ->setCreneauVisite($creneauVisite)
            ->setCreneauEntree($creneauEntree)
            ->setPointRDV($pointRDV)
            ->setEtablissement($etablissement);
        $this->em->persist($visite);
        $this->em->flush();

        return $visite;
    }

    private function dateTime(mixed $valeur, string $champ): \DateTimeImmutable
    {
        if (!\is_string($valeur) || $valeur === '') {
            throw new UnprocessableEntityHttpException(sprintf('Champ « %s » obligatoire (datetime ISO-8601).', $champ));
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('Champ « %s » invalide (datetime ISO-8601 attendu).', $champ));
        }
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
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » invalide (UUID ou IRI).', $champ));
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
