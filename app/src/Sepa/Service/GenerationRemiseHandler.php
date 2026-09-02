<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Enum\SeqTpSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Enum\StatutRemiseSepa;
use App\Sepa\Port\CollecteurSepaInterface;
use App\Sepa\Port\EcheanceSepaSource;
use App\Sepa\Service\DebitPreNotifier;
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
    /**
     * SEPA ne connait que l'euro — ce n'est pas un reglage, c'est la definition du dispositif.
     *
     * La constante existe pour que la comparaison ci-dessous se lise, pas pour qu'on la change.
     */
    private const DEVISE_SEPA = 'EUR';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeqTpResolver $seqTpResolver,
        private readonly Pain008Generator $generator,
        private readonly CollecteurSepaInterface $collecteur,
        private readonly DebitPreNotifier $preNotifier,
    ) {
    }

    public function generer(Etablissement $etablissement, \DateTimeImmutable $dateExecution, EcheanceSepaSource $source): RemiseSepa
    {
        // ⚠ SEPA EST UN DISPOSITIF EN EUROS. UNE REMISE DANS UNE AUTRE DEVISE N'EXISTE PAS.
        //
        // `Pain008Generator` ecrit `Ccy="EUR"` sans condition — et c'est JUSTE : le prelevement SEPA
        // ne connait que l'euro. Ce qui serait faux, c'est de laisser passer un etablissement qui
        // compte dans une autre unite : ses montants partiraient tels quels, ETIQUETES EUR, et la
        // banque prelverait ce nombre-la en euros, sur de vrais comptes.
        //
        // Ce cas n'etait pas atteignable hier. Il l'est depuis que l'etablissement porte une devise :
        // une capacite neuve ouvre une porte que rien ne gardait.
        //
        // ⚠ EN PREMIER, AVANT MEME DE CHERCHER LA CONFIGURATION CREANCIER. Ma premiere version etait
        // posee apres, et surtout APRES UN `throw` : elle vivait donc dans la branche « pas de
        // config » et ne s'executait que quand la fonction avait deja renonce. Le code compilait, les
        // 68 tests SEPA restaient verts, et le controle n'existait que dans son commentaire.
        //
        // C'est le test que j'ai ecrit ENSUITE qui l'a dit — pas la relecture.
        if ($etablissement->getDevise() !== self::DEVISE_SEPA) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Le prelevement SEPA n existe qu en euros : cet etablissement compte en %s. '
                . 'Generer la remise etiquetterait ses montants EUR et prelverait le mauvais montant.',
                $etablissement->getDevise(),
            ));
        }

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
        $nbExclues = 0;
        /** @var array<string, true> $motifsExclusion */
        $motifsExclusion = [];
        $totalCentimes = 0;
        $index = 0;
        foreach ($dues as $due) {
            $mandat = $this->em->getRepository(MandatSepa::class)->find($due->mandatId);
            if (!$mandat instanceof MandatSepa) {
                // Défensif : une échéance dont le mandat n'existe plus n'est pas incluse dans la remise.
                continue;
            }

            // ⚠ CE FILTRE N'EST PAS PRÉVENTIF : IL CORRIGE UN PRÉLÈVEMENT SUR MANDAT RÉVOQUÉ.
            //
            // `DemanderResiliationHandler` passe le mandat à `Revoque` quand un adhérent résilie et
            // qu'aucun autre abonnement ne s'y appuie. Rien n'annule ses échéances restantes — les
            // seules sorties de `StatutEcheanceSepa::AVenir` dans tout le dépôt sont `Prelevee`,
            // `Rejetee` et `Gelee`, et le seul écouteur sur `Resiliation` est l'audit. Or
            // `SportEcheanceSepaSource` JOINT le mandat (`->join('a.mandatSepa', 'm')`) pour en tirer
            // l'identifiant, sans jamais filtrer dessus. L'adhérent qui résiliait était donc prélevé
            // sur un mandat révoqué, à la remise suivante.
            //
            // Ne pas retirer ces lignes comme du code mort : l'état est atteint tous les jours par la
            // résiliation. `CardDebitFallback` gardait déjà la bascule carte, et
            // `ReservationEcheanceSepaSource` filtre `['statut' => Actif]` — le chemin principal était
            // le seul à ne rien regarder.
            //
            // L'exclusion est comptée et nommée comme celle du préavis, plutôt que de faire échouer
            // toute la remise : les échéances en règle partent, et ce qui est retenu s'explique.
            if (StatutMandatSepa::Actif !== $mandat->getStatut()) {
                ++$nbExclues;
                $motifsExclusion[sprintf('mandat non actif (%s)', $mandat->getStatut()->value)] = true;
                continue;
            }

            // PAY-2 — on ne prélève pas quelqu'un qu'on n'a pas prévenu. L'échéance non couverte sort
            // de la remise au lieu de la faire échouer : celles qui sont en règle partent, et ce qui
            // est retenu est compté et expliqué plus bas. Un tout-ou-rien retiendrait aussi les
            // échéances régulières, sans rien apprendre à personne.
            $raison = $this->preNotifier->reasonNotCovered(
                $mandat,
                $due->referenceOrigine,
                $due->montantCentimes,
                $dateExecution,
            );
            if (null !== $raison) {
                ++$nbExclues;
                $motifsExclusion[$raison] = true;
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

        $remise->setNbExclues($nbExclues)
            ->setMotifExclusion([] === $motifsExclusion ? null : implode(' ; ', array_keys($motifsExclusion)));

        if ($remise->getLignes()->isEmpty()) {
            // Une remise vide parce que TOUT a été exclu n'est pas une remise vide faute d'échéances.
            // Les confondre ferait chercher un défaut de mandat là où il manque un préavis.
            if ($nbExclues > 0) {
                throw new UnprocessableEntityHttpException(sprintf(
                    // ⚠ CE MESSAGE NE NOMME PLUS UNE SEULE CAUSE. Il disait « écartée(s) faute de
                    // préavis » quand le défaut de préavis était la seule exclusion possible ; depuis
                    // le filtre sur le statut du mandat, il y en a deux, et affirmer la première
                    // enverrait chercher un préavis manquant là où un mandat est révoqué. Les motifs
                    // réels sont déjà collectés — le message les rend, il ne les devine pas.
                    'Aucun prélèvement n\'est autorisé : %d échéance(s) due(s) sur %d écartée(s). Motif(s) : %s. '
                    .'Un prélèvement suppose deux choses : un mandat actif, et un préavis parti — le '
                    .'débiteur doit connaître le montant et la date. Tant que l\'une manque, rien ne '
                    .'peut être collecté.',
                    $nbExclues,
                    \count($dues),
                    implode(' ; ', array_keys($motifsExclusion)),
                ));
            }

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

        // ⚠ LE COMPTEUR DE COLLECTES NE BOUGE PLUS ICI, ET C'EST DELIBERE.
        //
        // Cette boucle appelait `incrementerCollectesReussies()` sur chaque mandat, en lisant le
        // retour du port comme un accuse de reception. Il n'en est pas un — et il ne le serait
        // toujours pas avec une vraie banque : transmettre un pain.008 n'est pas collecter. La
        // collecte se confirme des jours plus tard, par l'absence de rejet ou par un credit CAMT.
        //
        // ⚠ CE QUE CE COMPTEUR DECIDE : `SeqTpResolver` en deduit `RCUR` des qu'il depasse zero,
        // `FRST` sinon. Un mandat compte a tort partait en RCUR a son PREMIER prelevement reel —
        // motif de rejet bancaire, sur de l'argent, mandat par mandat.
        //
        // ⚠ ET RIEN NE LE DECREMENTAIT. `DeclarerRejetSepaProcessor` — la saisie manuelle d'un
        // rejet, qui existe et a un ecran — lit le mandat sans toucher au compteur. Un mandat
        // rejete restait donc compte comme collecte : le defaut etait atteignable AUJOURD'HUI, et
        // pas seulement le jour d'un raccordement.
        //
        // CONSEQUENCE ASSUMEE : plus rien n'incremente, donc tout part en FRST jusqu'a ce qu'un
        // vrai collecteur confirme des collectes. C'est le sens SUR de l'erreur — un FRST presente
        // a tort est generalement accepte, un RCUR presente a tort est rejete.
        //
        // RESTE A FAIRE au raccordement, et pas dans ce lot : incrementer a la CONFIRMATION de
        // collecte, et decrementer sur rejet. Les deux ensemble, sinon on reconstruit le meme
        // defaut dans l'autre sens.
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
