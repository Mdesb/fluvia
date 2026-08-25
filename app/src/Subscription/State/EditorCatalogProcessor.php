<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Subscription\ApiResource\EditorPlan;
use App\Subscription\ApiResource\EditorPlanOption;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Subscription\Security\EditorOnly;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Écriture du catalogue commercial, réservée à l'éditeur (ED-6).
 *
 * **Le contrôle d'accès est ici, pas dans une expression `security`.** Une expression sait dire « a
 * cette permission » ; elle ne sait pas dire « est l'éditeur », qui est une identité de tenant. Le
 * placer dans le processeur garantit aussi qu'il couvre la création, la modification **et** la
 * suppression, sans qu'on puisse en oublier une en ajoutant une opération.
 *
 * **On ne met pas en vente un module qui n'existe pas.** Une option dont la capacité est inconnue du
 * catalogue technique se vendrait normalement et ne se livrerait jamais (RG-ED-03) — refus explicite
 * à l'écriture, plutôt qu'un client qui découvre après paiement.
 *
 * **La suppression laisse vivre les abonnements en cours.** Retirer une formule du catalogue
 * n'annule aucun contrat : les abonnements qui la portent gardent leur prix. C'est aussi pourquoi
 * l'écran propose de décocher « en vente » plutôt que de supprimer.
 *
 * @implements ProcessorInterface<EditorPlan|EditorPlanOption, EditorPlan|EditorPlanOption|null>
 */
final class EditorCatalogProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly CatalogueCapacites $capacites,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->editorOnly->assertEditor();

        if ($operation instanceof DeleteOperationInterface) {
            $this->supprimer($data, $uriVariables);

            return null;
        }

        return $data instanceof EditorPlanOption
            ? $this->ecrireOption($data, $uriVariables)
            : $this->ecrireFormule($data, $uriVariables);
    }

    private function ecrireFormule(EditorPlan $vue, array $uriVariables): EditorPlan
    {
        $plan = isset($uriVariables['id'])
            ? $this->trouver(Plan::class, $uriVariables['id'])
            : new Plan();
        \assert($plan instanceof Plan);

        foreach ($vue->includedCapabilities as $capacite) {
            $this->assertCapaciteReelle($capacite);
        }

        $plan->setCode($vue->code)
            ->setLabel($vue->label)
            ->setMonthlyPriceCents($vue->monthlyPriceCents)
            ->setIncludedCapabilities(array_values($vue->includedCapabilities))
            ->setActive($vue->active);

        $this->em->persist($plan);
        $this->em->flush();

        $vue->id = $plan->getId()->toRfc4122();

        return $vue;
    }

    private function ecrireOption(EditorPlanOption $vue, array $uriVariables): EditorPlanOption
    {
        $option = isset($uriVariables['id'])
            ? $this->trouver(PlanOption::class, $uriVariables['id'])
            : new PlanOption();
        \assert($option instanceof PlanOption);

        $this->assertCapaciteReelle($vue->capability);

        $option->setCapability($vue->capability)
            ->setLabel($vue->label)
            ->setMonthlyPriceCents($vue->monthlyPriceCents)
            ->setActive($vue->active);

        $this->em->persist($option);
        $this->em->flush();

        $vue->id = $option->getId()->toRfc4122();

        return $vue;
    }

    private function supprimer(mixed $data, array $uriVariables): void
    {
        $classe = $data instanceof EditorPlanOption ? PlanOption::class : Plan::class;
        $entite = $this->trouver($classe, $uriVariables['id'] ?? null);

        $this->em->remove($entite);
        $this->em->flush();
    }

    /**
     * @param class-string $classe
     *
     * @cloisonnement-verifie: le catalogue commercial est GLOBAL a l editeur — `Plan` et
     * `PlanOption` ne portent aucun etablissement, il n y a donc pas d etablissement d entite a
     * confronter. Le perimetre est verifie en amont, une fois, par `EditorOnly::assertEditor()` :
     * l appelant est le tenant editeur ou il recoit 404 avant meme d atteindre cette resolution.
     * Un identifiant mal forme ou inexistant rend le meme 404, sans distinction observable.
     */
    private function trouver(string $classe, mixed $id): object
    {
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        $entite = $this->em->getRepository($classe)->find(Uuid::fromString($id));
        if (null === $entite) {
            throw new NotFoundHttpException();
        }

        return $entite;
    }

    private function assertCapaciteReelle(string $code): void
    {
        if ($this->capacites->existe($code)) {
            return;
        }

        throw new UnprocessableEntityHttpException(sprintf(
            'Le module « %s » n\'existe pas dans la plateforme. Le mettre en vente promettrait à un '
            .'client quelque chose qu\'on ne saurait pas lui livrer.',
            $code,
        ));
    }
}
