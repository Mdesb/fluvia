<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Enum\CanalResolutionImpaye;
use App\Recouvrement\Enum\StatutIncidentImpaye;
use App\Recouvrement\Event\IncidentImpayeReouvertureForceeEvent;
use App\Recouvrement\Event\IncidentImpayeResoluEvent;
use App\Recouvrement\Port\EncaissementImmediatInterface;
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
    ) {
    }

    public function resoudre(IncidentImpaye $incident): IncidentImpaye
    {
        if ($incident->getStatut() === StatutIncidentImpaye::Resolu) {
            throw new UnprocessableEntityHttpException('Incident déjà résolu.');
        }

        $initiation = $this->encaissement->initierPaiement($incident->getId(), $incident->getMontantCentimes());
        $confirmation = $this->encaissement->confirmerPaiement($initiation->referenceTransaction);
        if (!$confirmation->confirme) {
            throw new UnprocessableEntityHttpException('Encaissement non confirmé, impayé non résolu.');
        }

        $incident->setStatut(StatutIncidentImpaye::Resolu)
            ->setCanalResolution(CanalResolutionImpaye::App1Clic)
            ->setDateResolution($confirmation->dateConfirmation)
            ->setAccesBloque(false);

        $this->em->flush();

        // ⚠ RÉÉVALUER, PAS ACTIVER : régler CE dossier ne dit rien des autres. Un client qui
        // doit encore de l'argent ne doit pas retrouver son accès parce qu'il a réglé autre chose.
        $this->propagation->reevaluer($incident->getTypeRedevable(), $incident->getReferenceRedevable());
        $this->dispatcher->dispatch(new IncidentImpayeResoluEvent($incident, $incident->getMontantCentimes(), $confirmation->dateConfirmation, 'resolution_1_clic'));

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
