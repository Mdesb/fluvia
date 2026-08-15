<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\RejetSepa;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sepa/rejets (plan §2/§4/§6) : saisie/simulation manuelle d'un retour SEPA en attendant le
 * retour bancaire réel (`RetourSepaInterface`, aucun parser pain.002 réel — §9 du plan). Corps :
 *   { "ligne": iri|uuid, "codeMotif": string, "libelleMotif"?: string, "dateRejet"?: "AAAA-MM-JJ" }.
 *
 * @implements ProcessorInterface<mixed, RejetSepa>
 */
final class DeclarerRejetSepaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RejetSepa
    {
        $corps = $this->lecteur->corps();

        $ligne = $this->resoudreLigne($corps['ligne'] ?? null);
        if (!$ligne instanceof LigneRemiseSepa) {
            throw new UnprocessableEntityHttpException('« ligne » est requise et doit référencer une ligne de remise SEPA existante.');
        }

        $codeMotif = \is_string($corps['codeMotif'] ?? null) ? trim($corps['codeMotif']) : '';
        if ($codeMotif === '') {
            throw new UnprocessableEntityHttpException('« codeMotif » est requis (code retour SEPA, ex. AM04).');
        }
        $libelle = isset($corps['libelleMotif']) && \is_string($corps['libelleMotif']) ? $corps['libelleMotif'] : null;
        $dateRejet = isset($corps['dateRejet']) && \is_string($corps['dateRejet'])
            ? new \DateTimeImmutable($corps['dateRejet'])
            : new \DateTimeImmutable('today');

        $mandat = $ligne->getMandat();

        $rejet = new RejetSepa();
        $rejet->setLigne($ligne)
            ->setEndToEndId($ligne->getEndToEndId())
            ->setMndtId($mandat?->getRum() ?? '')
            ->setCodeMotif($codeMotif)
            ->setLibelleMotif($libelle)
            ->setDateRejet($dateRejet);

        $this->em->persist($rejet);
        $this->em->flush();

        return $rejet;
    }

    private function resoudreLigne(mixed $valeur): ?LigneRemiseSepa
    {
        if (!\is_string($valeur) || trim($valeur) === '') {
            return null;
        }
        $id = str_contains($valeur, '/') ? substr($valeur, (int) strrpos($valeur, '/') + 1) : $valeur;
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(LigneRemiseSepa::class)->find($id);
    }
}
