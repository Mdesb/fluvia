<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Enum\CanalResolutionImpaye;
use App\Recouvrement\Enum\StatutIncidentImpaye;
use App\Recouvrement\Event\IncidentImpayeReouvertureForceeEvent;
use App\Recouvrement\Event\IncidentImpayeResoluEvent;
use App\Recouvrement\Port\EncaissementImmediatInterface;
use App\Vente\Port\ReferentielReglementInterface;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Résolution 1 clic — moteur générique partagé (extrait de
 * `App\Sport\Service\ResolutionImpayeHandler`) : encaissement CB immédiat, résolution de l'incident,
 * et **restauration automatique de l'accès sans intervention d'un agent**.
 */
final class ResolutionImpayeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EncaissementImmediatInterface $encaissement,
        private readonly PropagationAccesHandler $propagation,
        private readonly EventDispatcherInterface $dispatcher,
        // La frontiere prevue vers le referentiel des moyens de paiement (RG-M2-02) : un PORT, pas un
        // appel direct a `App\Compta`. Son en-tete dit deja la convention retenue ici — « le code du
        // moyen reste stocke en clair ».
        private readonly ReferentielReglementInterface $referentielMoyens,
    ) {
    }

    /**
     * Constate le règlement d'un impayé.
     *
     * ⚠ « RÉGLÉ » ÉTAIT UN CLIC MUET, ET C'EST CE QU'ON CORRIGE ICI. Jusqu'au 08/09, l'opération
     * n'acceptait aucun corps (`input: false`) et posait `CanalResolutionImpaye::App1Clic` **en dur** :
     * l'enum portait `virement`, `caisse` et `autre` qu'aucun chemin ne pouvait produire, et la colonne
     * « Par quel canal » de l'écran affichait éternellement la même valeur. On ne savait ni comment ni
     * quand l'argent était rentré.
     *
     * ⚠ ET LE PORT D'ENCAISSEMENT N'EST APPELÉ QUE POUR LE CANAL QUI L'EXIGE. Les canaux de constat —
     * virement, caisse, autre — ne parlent à aucun prestataire : l'agent déclare que l'argent est
     * rentré par un chemin qui n'est pas le nôtre. Seul `app_1_clic` prétend débiter une carte, et il
     * échoue tant qu'aucun PSP n'est raccordé (voir `EncaissementImmediatStubAdapter`).
     *
     * @throws UnprocessableEntityHttpException incident déjà résolu, moyen inconnu, référence
     *                                          manquante alors que le moyen l'exige, ou encaissement
     *                                          non confirmé
     */
    public function resoudre(
        IncidentImpaye $incident,
        CanalResolutionImpaye $canal,
        string $codeMoyen,
        \DateTimeImmutable $dateEncaissement,
        ?string $reference,
        Utilisateur $auteur,
    ): IncidentImpaye {
        if ($incident->getStatut() === StatutIncidentImpaye::Resolu) {
            throw new UnprocessableEntityHttpException('Incident déjà résolu.');
        }

        $moyen = $this->referentielMoyens->moyen($codeMoyen);
        if ($moyen === null) {
            throw new UnprocessableEntityHttpException(sprintf('Moyen de paiement inconnu : « %s ».', $codeMoyen));
        }

        // La contrainte vient du référentiel, pas d'une liste recopiée ici : un chèque ou une CB
        // exigent un numéro, et c'est l'exploitant qui le déclare sur son moyen.
        $reference = \is_string($reference) ? trim($reference) : null;
        if ($moyen->exigeReference && ($reference === null || $reference === '')) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Le moyen « %s » exige une référence (numéro de chèque, de virement…).',
                $moyen->libelle,
            ));
        }

        $dateEffective = $dateEncaissement;
        if ($canal === CanalResolutionImpaye::App1Clic) {
            $initiation = $this->encaissement->initierPaiement($incident->getId(), $incident->getMontantCentimes());
            $confirmation = $this->encaissement->confirmerPaiement($initiation->referenceTransaction);
            if (!$confirmation->confirme) {
                throw new UnprocessableEntityHttpException(
                    "Encaissement par carte impossible : aucun prestataire de paiement n'est raccordé. "
                    . "Si l'argent est rentré par un autre chemin, déclarez le canal correspondant "
                    . '(virement, caisse, autre).',
                );
            }
            $dateEffective = $confirmation->dateConfirmation;
        }

        $incident->setStatut(StatutIncidentImpaye::Resolu)
            ->setCanalResolution($canal)
            ->setMoyenResolution($moyen->code)
            ->setReferenceResolution($reference)
            ->setDateResolution($dateEffective)
            ->setResoluPar($auteur)
            ->setAccesBloque(false);

        $this->em->flush();

        // ⚠ RÉÉVALUER, PAS ACTIVER : régler CE dossier ne dit rien des autres. Un client qui
        // doit encore de l'argent ne doit pas retrouver son accès parce qu'il a réglé autre chose.
        $this->propagation->reevaluer($incident->getTypeRedevable(), $incident->getReferenceRedevable());

        // L'événement porte l'incident, qui porte désormais le canal, le moyen, la référence et la
        // pièce d'origine : l'abonné de `Facturation` y trouve tout ce qu'il lui faut pour écrire le
        // règlement, sans que `Recouvrement` ait à connaître son module (D2).
        $this->dispatcher->dispatch(new IncidentImpayeResoluEvent($incident, $incident->getMontantCentimes(), $dateEffective, 'resolution_' . $canal->value));

        return $incident;
    }

    /** Réouverture forcée par un agent habilité (motif requis, traçabilité RG-SOCLE-07). */
    public function forcerReouverture(IncidentImpaye $incident, Utilisateur $agent, string $motif): IncidentImpaye
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Un motif est requis pour une réouverture forcée (RG-SOCLE-07).');
        }

        $incident->setReouvertureForceePar($agent)->setMotifReouvertureForcee($motif)->setAccesBloque(false);

        $this->em->flush();

        // ⚠ RÉÉVALUER MÊME ICI, ET C'EST UN CHOIX. Forcer la réouverture sur le dossier de mars
        // alors qu'avril bloque encore n'ouvrira pas la porte : l'agent devra forcer aussi sur
        // avril. Plus de gestes, et le bon comportement — chaque blocage levé porte alors SON
        // motif. L'inverse ferait lever, d'un seul motif, des blocages que personne n'a examinés.
        $this->propagation->reevaluer($incident->getTypeRedevable(), $incident->getReferenceRedevable());
        $this->dispatcher->dispatch(new IncidentImpayeReouvertureForceeEvent($incident));

        return $incident;
    }
}
