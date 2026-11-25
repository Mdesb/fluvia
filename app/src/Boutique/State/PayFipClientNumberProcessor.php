<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Écriture du numéro client PayFiP d'une régie (lot D2-B de #365), `PATCH …/payfip_client_number`.
 *
 * - **Périmètre** : le profil doit avoir l'établissement ACTIF pour **principal** (comme la clé Stripe). L'extension
 *   de périmètre rend déjà l'item introuvable (404) ; la comparaison ci-dessous double ce filtre.
 * - **Régie directe** : seule une régie directe encaisse par PayFiP. Une SAISIE sur un autre type est refusée (422) ;
 *   l'EFFACEMENT reste permis, pour retirer le reliquat d'un changement de type (décision par défaut, UNVERIFIED 4).
 *   Ce processeur vit côté Boutique parce que `ArchitectureRegimeTest` interdit à Compta de lire `ProfilExploitant::type`.
 * - **Rien n'est écrit** si la valeur est inchangée (pas d'entrée d'audit vide).
 * - Le format (6 chiffres) est jugé AVANT ce processeur par la contrainte de l'entité ; ce processeur ne pose pas le
 *   champ lui-même, la désérialisation l'a fait.
 *
 * @implements ProcessorInterface<ProfilExploitant, ProfilExploitant>
 */
final class PayFipClientNumberProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $context,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProfilExploitant
    {
        \assert($data instanceof ProfilExploitant);

        $active = $this->context->etablissementActif();
        $principal = $data->getEtablissementPrincipal();
        if ($active === null || $principal === null || !$principal->getId()->equals($active->getId())) {
            throw new NotFoundHttpException('Profil exploitant introuvable.');
        }

        $before = $this->em->getUnitOfWork()->getOriginalEntityData($data)['payfipClientNumber'] ?? null;
        $after = $data->getPayfipClientNumber();

        if ($after !== null && $data->getType() !== TypeExploitant::RegieDirecte) {
            // La valeur désérialisée n'est pas flushée (l'exception met fin à la requête), mais on la rend
            // à l'état d'origine : aucun flush ultérieur ne doit pouvoir l'emporter.
            $data->setPayfipClientNumber($before);

            throw new UnprocessableEntityHttpException('Seule une régie directe encaisse par PayFiP : ce profil n\'a pas de numéro client à saisir.');
        }

        if ($before === $after) {
            return $data;
        }

        $data->toucherModifieLe();
        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
