<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Enum\SeqTpSepa;
use App\Sepa\Enum\StatutRemiseSepa;
use App\Sepa\Port\CollecteurSepaInterface;
use App\Sepa\Port\EcheanceSepaSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Génère et transmet une remise SEPA pour un établissement à une date d'exécution donnée (plan §3) :
 * interroge le port `EcheanceSepaSource` fourni par la verticale appelante (Sport, Piscine…), résout
 * la `SeqTp` de chaque échéance (`SeqTpResolver`), construit la remise + ses lignes, génère le pain.008
 * (`Pain008Generator`, régime-aware via `ConfigCreancierSepa`), le transmet (`CollecteurSepaInterface`)
 * puis notifie la verticale des échéances collectées (`EcheanceSepaSource::marquerCollectees()`).
 */
final class GenerationRemiseHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeqTpResolver $seqTpResolver,
        private readonly Pain008Generator $generator,
        private readonly CollecteurSepaInterface $collecteur,
    ) {
    }

    public function generer(Etablissement $etablissement, \DateTimeImmutable $dateExecution, EcheanceSepaSource $source): RemiseSepa
    {
        $config = $this->em->getRepository(ConfigCreancierSepa::class)->findOneBy(['etablissement' => $etablissement]);
        if (!$config instanceof ConfigCreancierSepa) {
            throw new UnprocessableEntityHttpException('Aucune configuration créancier SEPA (« ConfigCreancierSepa ») pour cet établissement.');
        }

        $dues = $source->echeancesDues($etablissement, $dateExecution);
        if ($dues === []) {
            throw new UnprocessableEntityHttpException('Aucune échéance due pour cette date.');
        }

        $remise = new RemiseSepa();
        $remise->setEtablissement($etablissement)
            ->setDateCreation(new \DateTimeImmutable())
            ->setDateCollecte($dateExecution)
            ->setStatut(StatutRemiseSepa::Brouillon);
        $this->em->persist($remise);
        $this->em->flush();

        $remise->setMessageId('REMISE-' . strtoupper(substr(hash('sha256', (string) $remise->getId()), 0, 20)));

        $referencesOrigine = [];
        $seqTpVus = [];
        $totalCentimes = 0;
        $index = 0;
        foreach ($dues as $due) {
            $mandat = $this->em->getRepository(MandatSepa::class)->find($due->mandatId);
            if (!$mandat instanceof MandatSepa) {
                // Défensif : une échéance dont le mandat n'existe plus n'est pas incluse dans la remise.
                continue;
            }

            $seqTp = $this->seqTpResolver->resoudre($mandat, $due->derniereEcheanceEngagement, $due->paiementUnique);
            $seqTpVus[$seqTp->value] = true;

            $ligne = new LigneRemiseSepa();
            $ligne->setMandat($mandat)
                ->setSeqTp($seqTp)
                ->setMontantCentimes($due->montantCentimes)
                ->setEndToEndId($this->genererEndToEndId($remise, ++$index))
                ->setLibelle($due->libelle)
                ->setReferenceOrigine($due->referenceOrigine);
            $remise->addLigne($ligne);
            $this->em->persist($ligne);

            $referencesOrigine[] = $due->referenceOrigine;
            $totalCentimes += $due->montantCentimes;
            $mandat->setSequenceCourante($seqTp);
        }

        if ($remise->getLignes()->isEmpty()) {
            throw new UnprocessableEntityHttpException('Aucune échéance due ne référence un mandat SEPA existant.');
        }

        $remise->setNbTxs($remise->getLignes()->count())
            ->setCtrlSumCentimes($totalCentimes)
            ->setSeqTp(\count($seqTpVus) === 1 ? SeqTpSepa::from((string) array_key_first($seqTpVus)) : null)
            ->setStatut(StatutRemiseSepa::Generee);

        $remise->setContenuXml($this->generator->generer($remise, $config));
        $this->em->flush();

        $referenceTransmission = $this->collecteur->transmettre($remise);
        $remise->setReferenceTransmission($referenceTransmission)->setStatut(StatutRemiseSepa::Transmise);

        foreach ($remise->getLignes() as $ligne) {
            $ligne->getMandat()?->incrementerCollectesReussies();
        }
        $this->em->flush();

        $source->marquerCollectees($remise, $referencesOrigine);
        $this->em->flush();

        return $remise;
    }

    private function genererEndToEndId(RemiseSepa $remise, int $index): string
    {
        return strtoupper(substr(hash('sha256', (string) $remise->getId() . $index), 0, 16));
    }
}
