<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Patinoire\Entity\Affutage;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Enum\StatutAffutage;
use App\Patinoire\Enum\TypeAffutage;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Entity\LigneVente;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Démarre un affûtage (POST /patinoire/affutages, US-PATIN-06/07, RG-PAT-06, décision actée
 * « affûtage »). Corps : { "type": "prestation_client"|"maintenance_parc", "technicien": iri|uuid,
 * "ligneVente"?: iri|uuid, "parcPatins"?: iri|uuid (requis si maintenance_parc) }.
 *
 * **`etablissement` n'est plus un champ du corps (D3/D8, D41).** En `prestation_client`, le parc ne
 * porte pas le rattachement, et la version précédente le demandait donc à l'appelant — résolu par un
 * `find()` sans confrontation au périmètre. L'établissement vient désormais de la session serveur.
 * Ce que l'appelant envoyait était son propre établissement ; ce qui disparaît, c'est la possibilité
 * d'en désigner un autre.
 * `maintenance_parc` immobilise immédiatement
 * l'article (`quantiteEnAffutage++`, sort du disponible, CA-7) ; `prestation_client` n'a **aucun
 * impact** sur le parc (CA-6, patins personnels du client).
 *
 * @implements ProcessorInterface<mixed, Affutage>
 */
final class DemarrerAffutageProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Affutage
    {
        $corps = $this->lecteur->corps();

        $type = TypeAffutage::tryFrom(\is_string($corps['type'] ?? null) ? $corps['type'] : '');
        if ($type === null) {
            throw new UnprocessableEntityHttpException('Champ « type » obligatoire (prestation_client|maintenance_parc).');
        }

        $technicien = $this->resoudre(Utilisateur::class, $corps['technicien'] ?? null, 'technicien');
        \assert($technicien instanceof Utilisateur);

        $affutage = new Affutage();
        $affutage->setType($type)->setTechnicien($technicien);

        if ($type === TypeAffutage::MaintenanceParc) {
            $parcPatins = $this->resoudre(ParcPatins::class, $corps['parcPatins'] ?? null, 'parcPatins');
            \assert($parcPatins instanceof ParcPatins);
            $affutage->setParcPatins($parcPatins)->setEtablissement($parcPatins->getEtablissement());
            $parcPatins->incrementerEnAffutage(1);
            $affutage->setStatut(StatutAffutage::EnCours);
        } else {
            if (isset($corps['ligneVente'])) {
                $ligneVente = $this->resoudre(LigneVente::class, $corps['ligneVente'], 'ligneVente');
                \assert($ligneVente instanceof LigneVente);
                $affutage->setLigneVente($ligneVente);
            }
            // D3/D8 + D41 : le perimetre vient de la session serveur, jamais du corps. Avant, cette
            // branche resolvait l'etablissement par un `find()` nu sur une reference fournie par
            // l'appelant : un exploitant de A pouvait ouvrir un affutage chez B, et la victime
            // l'aurait vu apparaitre dans ses ecrans sans jamais savoir d'ou il venait.
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                throw new UnprocessableEntityHttpException('Aucun etablissement actif : impossible de rattacher cet affutage.');
            }
            $affutage->setEtablissement($etablissement);
            $affutage->setStatut(StatutAffutage::EnCours);
        }

        $this->em->persist($affutage);
        $this->em->flush();

        return $affutage;
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
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
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
