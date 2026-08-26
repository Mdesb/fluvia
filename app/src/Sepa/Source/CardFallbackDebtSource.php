<?php

declare(strict_types=1);

namespace App\Sepa\Source;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\CardFallbackDebt;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Port\EcheanceSepaSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Présente à la collecte les dettes nées d'un refus de carte (PAY-2, D43).
 *
 * **Une source comme les autres, et c'est ce qui la rend sûre.** Sport et Piscine alimentent déjà le
 * module SEPA par ce port ; la bascule s'y range plutôt que d'ouvrir un second chemin de collecte. Un
 * chemin parallèle aurait échappé au préavis, au cloisonnement et au comptage des exclues — trois
 * garanties qu'on aurait perdues sans s'en apercevoir, puisque les prélèvements seraient partis.
 *
 * **La référence et le montant sont ceux du préavis, à l'identique.** C'est la seule chose qui compte
 * ici : `DebitPreNotifier::covers()` compare les deux, et une source qui arrondirait, recalculerait ou
 * renommerait ferait reconnaître « une annonce qui ressemble à la bonne sans en être une ». Tout
 * serait vert et rien ne serait couvert.
 *
 * **Rien n'est présenté avant `dueDate`**, qui est la date à laquelle le préavis aura couru. La garde
 * est donc double — ici par la requête, et dans `GenerationRemiseHandler` par `covers()`. Ce n'est pas
 * une redondance inutile : celle-ci évite de proposer une échéance qui serait de toute façon écartée,
 * et de gonfler le compte des exclues d'un cas normal.
 */
#[AutoconfigureTag('sepa.echeance_source')]
final class CardFallbackDebtSource implements EcheanceSepaSource
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<EcheanceSepaDue> */
    public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array
    {
        /** @var list<CardFallbackDebt> $dettes */
        $dettes = $this->em->getRepository(CardFallbackDebt::class)->createQueryBuilder('d')
            ->innerJoin('d.mandate', 'm')
            // `IDENTITY()` et le type `uuid` explicite, plutôt que l'entité nue.
            //
            // Passer l'entité ici rend une liste **vide**, sans rien lever : Doctrine ne convertit pas
            // l'identifiant `Uuid` dans ce contexte. Et une liste vide ressemble exactement à « il n'y
            // a rien ». C'est le garde-fou n°14, écrit aujourd'hui même — et il m'a attrapé sur mon
            // propre code : le test trouvait zéro échéance là où la dette existait bel et bien.
            ->andWhere('IDENTITY(m.etablissement) = :etablissement')
            ->andWhere('d.collectedAt IS NULL')
            ->andWhere('d.dueDate <= :date')
            ->setParameter('etablissement', $etablissement->getId(), 'uuid')
            ->setParameter('date', $dateExecution)
            ->orderBy('d.dueDate', 'ASC')
            ->getQuery()
            ->getResult();

        $dues = [];
        foreach ($dettes as $dette) {
            $mandat = $dette->getMandate();
            if (null === $mandat) {
                continue;
            }

            $dues[] = new EcheanceSepaDue(
                referenceOrigine: $dette->getOriginReference(),
                mandatId: $mandat->getId(),
                montantCentimes: $dette->getAmountCents(),
                libelle: 'Paiement par carte refusé — prélèvement de remplacement',
                dateEcheance: $dette->getDueDate(),
                derniereEcheanceEngagement: false,
                // Une bascule est un prélèvement isolé : elle ne s'inscrit dans aucun échéancier, et
                // la séquence SEPA doit le dire (`OOFF` plutôt que `RCUR`), sans quoi la banque du
                // débiteur la traiterait comme une récurrence qu'il n'a jamais mise en place.
                paiementUnique: true,
            );
        }

        return $dues;
    }

    /** @param list<string> $referencesOrigine */
    public function marquerCollectees(RemiseSepa $remise, array $referencesOrigine): void
    {
        if ([] === $referencesOrigine) {
            return;
        }

        /** @var list<CardFallbackDebt> $dettes */
        $dettes = $this->em->getRepository(CardFallbackDebt::class)->createQueryBuilder('d')
            ->andWhere('d.originReference IN (:references)')
            ->andWhere('d.collectedAt IS NULL')
            ->setParameter('references', $referencesOrigine)
            ->getQuery()
            ->getResult();

        foreach ($dettes as $dette) {
            // L'instant métier est celui de la collecte, pas celui du traitement (D37).
            $dette->setCollectedAt($remise->getDateCollecte() ?? new \DateTimeImmutable());
        }

        $this->em->flush();
    }
}
