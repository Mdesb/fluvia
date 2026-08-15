<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Enum\NatureOperation;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Compta\Regime\Dto\AvoirProjectionDto;
use App\Compta\Regime\RegimeComptableResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/ecritures/{id}/extourne (RG-M2-07/§4.2) : seule voie de correction d'une écriture
 * scellée. Contre-passation totale, jamais de suppression (CA-7).
 *
 * @implements ProcessorInterface<EcritureComptable, EcritureComptable>
 */
final class ExtourneEcritureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegimeComptableResolver $resolver,
        private readonly ScellementEcritureHandler $scellement,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EcritureComptable
    {
        \assert($data instanceof EcritureComptable);

        $profil = $data->getProfilExploitant();
        if ($profil === null) {
            throw new ConflictHttpException('Extourne impossible : profil exploitant introuvable.');
        }
        if ($data->getPeriode() !== null && !$data->getPeriode()->estOuverte()) {
            throw new ConflictHttpException('Extourne impossible : période clôturée (RG-CLOTURE-10, §4.2 spec).');
        }

        $regime = $this->resolver->pour($profil);
        $avoir = new AvoirProjectionDto(
            id: Uuid::v4(),
            venteOrigine: $data->getVenteOrigine() ?? Uuid::v4(),
            montantCentimes: $data->totalDebitCentimes(),
            motif: 'Extourne manuelle',
            dateHeure: new \DateTimeImmutable(),
        );

        $dto = $regime->genererEcritureExtourne($data, $avoir);

        $extourne = new EcritureComptable();
        $extourne->setProfilExploitant($profil);
        $extourne->setJournal($regime->journalPour($profil, NatureOperation::Extourne));
        $extourne->setPeriode($data->getPeriode());
        $extourne->setDateEcriture(new \DateTimeImmutable());
        $extourne->setLibelle($dto->libelle);
        $extourne->setVenteOrigine($data->getVenteOrigine());
        $extourne->setPieceExtourneDe($data);
        $extourne->setStatut(StatutEcriture::Controlee);

        foreach ($dto->lignes as $ligneDto) {
            $ligne = new LigneEcriture();
            $ligne->setCompte($ligneDto->compte);
            $ligne->setDebitCentimes($ligneDto->debitCentimes);
            $ligne->setCreditCentimes($ligneDto->creditCentimes);
            $ligne->setTauxTva($ligneDto->tauxTva);
            $ligne->setAxeSite($ligneDto->axeSite);
            $ligne->setAxeActivite($ligneDto->axeActivite);
            $ligne->setAxeFinanceur($ligneDto->axeFinanceur);
            $ligne->setLibelle($ligneDto->libelle);
            $extourne->addLigne($ligne);
        }

        $this->scellement->sceller($extourne);
        $this->em->persist($extourne);
        $this->em->flush();

        return $extourne;
    }
}
