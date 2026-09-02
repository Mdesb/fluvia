<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sepa/mandats/{id}/revoquer — le débiteur retire son autorisation de prélèvement.
 *
 * `StatutMandatSepa::Revoque` n'était atteignable que par un chemin détourné : la résiliation d'un
 * abonnement fitness (`DemanderResiliationHandler`), qui révoque le mandat en effet de bord. Aucun
 * geste ne permettait de révoquer un mandat pour lui-même — un débiteur qui retire son autorisation
 * sans résilier d'abonnement n'avait aucune porte. Ce point d'entrée est celle-là.
 *
 * **Il n'a de sens que parce que `GenerationRemiseHandler` a d'abord appris à lire ce statut.** Avant
 * ce filtre, révoquer un mandat n'aurait rien arrêté : la remise suivante l'aurait inclus comme si de
 * rien n'était, et le bouton aurait affiché une protection qui n'existait pas.
 *
 * ⚠ AUCUN PROVIDER SUR MESURE, ET C'EST DÉLIBÉRÉ. Le cloisonnement par établissement de `MandatSepa`
 * vient de `PerimetreSepaExtension::applyToItem`, qui s'applique au provider PAR DÉFAUT. Déclarer un
 * provider maison ici — même trivial — court-circuiterait l'extension et rendrait révocable un mandat
 * d'un autre établissement. `read: true` suffit : API Platform charge l'entité par le chemin cloisonné
 * et la remet à ce processeur, déjà filtrée.
 *
 * ⚠ ET PAS DE CHEMIN INVERSE. Une révocation ne se défait pas : un mandat révoqué ne redevient pas
 * actif, le débiteur doit signer un nouveau mandat, qui porte un nouveau RUM et repart en séquence
 * FRST. Ajouter un « réactiver » ressusciterait une autorisation que son signataire a retirée.
 *
 * La date et l'auteur ne sont pas stockés sur l'entité : `MandatSepa` figure dans les classes
 * surveillées par `AuditWriteSubscriber`, qui journalise le changement d'état — qui, quand, avant et
 * après — dans la même transaction. Une colonne de plus dupliquerait ce que le journal porte déjà.
 *
 * @implements ProcessorInterface<MandatSepa, MandatSepa>
 */
final class RevoquerMandatSepaProcessor implements ProcessorInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MandatSepa
    {
        \assert($data instanceof MandatSepa);

        if (StatutMandatSepa::Actif !== $data->getStatut()) {
            // On refuse plutôt que de rendre 200 en silence : un second appel qui « réussit » sans
            // rien faire laisserait croire à une action, et le journal d'audit ne porterait qu'une
            // seule révocation pour deux gestes apparemment aboutis.
            throw new UnprocessableEntityHttpException(sprintf(
                'Ce mandat est déjà « %s » : il n\'autorise plus aucun prélèvement, et rien n\'a été '
                .'modifié. Le journal d\'audit porte la date et l\'auteur de sa révocation.',
                $data->getStatut()->value,
            ));
        }

        $data->setStatut(StatutMandatSepa::Revoque);
        $this->em->flush();

        return $data;
    }
}
