<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\ExpenseAccountMapping;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Écriture (Post/Patch) de `ExpenseAccountMapping` (§3 spec, point 3) : un utilisateur ne peut
 * créer/modifier un mapping que pour un `businessProfile` qu'il couvre — revérifié **avant** tout
 * `persist`/`flush`, pas seulement filtré en lecture (invariant noyau commun #3, « écriture bornée,
 * périmètre dans la requête »). Échec fermé : profil absent ou hors périmètre -> 404.
 *
 * Vérifie aussi, à la création, l'unicité applicative `(businessProfile, expenseNatureCode)` (§1 du
 * plan) : rejet 422 explicite avant toute tentative d'écriture, plutôt qu'une exception SQL brute.
 *
 * @implements ProcessorInterface<ExpenseAccountMapping, ExpenseAccountMapping>
 */
final class ExpenseAccountMappingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseAccountMapping
    {
        \assert($data instanceof ExpenseAccountMapping);

        $etablissementActif = $this->contexte->etablissementActif();
        $profil = $data->getBusinessProfile();

        if ($etablissementActif === null || $profil === null || !$profil->couvre($etablissementActif)) {
            throw new NotFoundHttpException('Profil exploitant introuvable.');
        }

        if (!isset($uriVariables['id'])) {
            $existant = $this->em->getRepository(ExpenseAccountMapping::class)->findOneBy([
                'businessProfile' => $profil->getId(),
                'expenseNatureCode' => $data->getExpenseNatureCode(),
            ]);
            if ($existant !== null) {
                throw new UnprocessableEntityHttpException(sprintf('Un mapping existe déjà pour la nature de charge « %s » sur ce profil exploitant.', $data->getExpenseNatureCode()));
            }
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
