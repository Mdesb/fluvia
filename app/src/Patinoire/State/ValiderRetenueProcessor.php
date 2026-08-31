<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caution\Entity\Caution;
use App\Caution\Entity\MouvementCaution;
use App\Caution\Enum\StatutCaution as StatutCautionGenerique;
use App\Caution\Service\GestionCaution;
use App\Patinoire\Entity\CautionLocationPatins;
use App\Patinoire\Entity\RetenueCaution;
use App\Patinoire\Enum\StatutCaution as StatutCautionLocation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Validation d'une retenue de caution (POST /patinoire/retenues/{id}/valider, US-PATIN-04, CA-4).
 * Corps : { "montantRetenu"?: decimal }. Garde-fou (§3 du plan, pas de Voter dédié) : si le montant
 * soumis diffère du montant par défaut proposé par la grille, la validation exige
 * `patinoire.forcer_retenue` (403 sinon) et positionne `forcee=true` — action journalisée (agent,
 * motif, horodatage, RG-SOCLE-07). Génère la trace comptable (`mouvementRegieRef`, référence logique
 * non-FK). Validation déléguée à `App\Caution\Service\GestionCaution::validerRetenue()` sur le
 * mouvement générique lié (`mouvementGeneriqueRef`, refactor caution générique) ; les résultats sont
 * mirroir dans l'entité locale `RetenueCaution` (contrat API inchangé).
 *
 * @implements ProcessorInterface<RetenueCaution, RetenueCaution>
 */
final class ValiderRetenueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly GestionCaution $gestionCaution,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RetenueCaution
    {
        \assert($data instanceof RetenueCaution);

        if ($data->estValidee()) {
            throw new ConflictHttpException('Cette retenue a déjà été validée.');
        }

        $corps = $this->lecteur->corps();
        $montantDefaut = $data->getMontantRetenu();
        $montantSoumis = isset($corps['montantRetenu']) ? number_format((float) $corps['montantRetenu'], 2, '.', '') : $montantDefaut;

        $agent = $this->security->getUser();
        $agentUtilisateur = $agent instanceof Utilisateur ? $agent : null;
        $autoriseForcage = $this->security->isGranted('PERM', 'patinoire.forcer_retenue');

        // @cloisonnement-verifie : $data (RetenueCaution) est chargé par l'opération `read: true`
        // /patinoire/retenues/{id}/valider et DÉJÀ confronté au périmètre par
        // PerimetrePatinoireExtension::applyToItem (RetenueCaution -> location -> etablissement) — 404 au
        // read pour un {id} d'un autre établissement. Le MouvementCaution ci-dessous (clefé par le ref
        // stocké SUR $data) et la CautionLocationPatins plus bas (clefée par $data->getLocation()) sont
        // donc transitivement dans le périmètre, aucune résolution par identifiant client non contrôlé.
        $mouvementGenerique = $data->getMouvementGeneriqueRef() !== null
            ? $this->em->getRepository(MouvementCaution::class)->find($data->getMouvementGeneriqueRef())
            : null;

        if ($mouvementGenerique instanceof MouvementCaution) {
            $mouvementValide = $this->gestionCaution->validerRetenue(
                $mouvementGenerique,
                Caution::decimalVersCentimes($montantSoumis),
                $agentUtilisateur,
                $autoriseForcage,
            );

            $data->setMontantRetenu($montantSoumis)
                ->setForcee($mouvementValide->isForcee())
                ->setAgent($agentUtilisateur)
                ->setMouvementRegieRef($mouvementValide->getMouvementRegieRef());

            $cautionGenerique = $mouvementValide->getCaution();
            $caution = $this->em->getRepository(CautionLocationPatins::class)->findOneBy(['location' => $data->getLocation()]);
            if ($caution instanceof CautionLocationPatins && $cautionGenerique instanceof Caution) {
                $totale = $cautionGenerique->getStatut() === StatutCautionGenerique::RetenueTotale;
                $caution->setStatut($totale ? StatutCautionLocation::RetenueTotale : StatutCautionLocation::RetenuePartielle);
            }
        } else {
            // Filet de sécurité (mouvement générique introuvable, ex. données historiques) : reproduit
            // le garde-fou RG-SOCLE-07 localement, sans mettre à jour le ledger générique.
            $forcee = Caution::decimalVersCentimes($montantSoumis) !== Caution::decimalVersCentimes($montantDefaut);
            if ($forcee && !$autoriseForcage) {
                throw new AccessDeniedHttpException('Montant hors barème : permission de forçage requise (RG-SOCLE-07).');
            }
            $data->setMontantRetenu($montantSoumis)->setForcee($forcee)->setAgent($agentUtilisateur)->setMouvementRegieRef(Uuid::v4());

            $caution = $this->em->getRepository(CautionLocationPatins::class)->findOneBy(['location' => $data->getLocation()]);
            if ($caution instanceof CautionLocationPatins) {
                $totale = Caution::decimalVersCentimes($montantSoumis) >= Caution::decimalVersCentimes($caution->getMontant());
                $caution->setStatut($totale ? StatutCautionLocation::RetenueTotale : StatutCautionLocation::RetenuePartielle);
            }
        }

        $this->em->flush();

        return $data;
    }
}
