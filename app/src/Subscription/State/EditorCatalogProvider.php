<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Subscription\ApiResource\EditorPlan;
use App\Subscription\ApiResource\EditorPlanOption;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Subscription\Security\EditorOnly;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture du catalogue commercial côté administration (ED-6).
 *
 * **Ce fournisseur rend ce que la vitrine cache**, et c'est la seule différence entre les deux.
 * {@see PublicPlansProvider} écarte les formules hors vente et celles qui promettent une capacité
 * inexistante : sur une page publique, annoncer ce qu'on ne sait pas livrer est une faute. Ici c'est
 * l'inverse — c'est le seul écran où ces formules peuvent être corrigées, donc le seul où elles
 * doivent apparaître.
 *
 * **Un fournisseur pour deux ressources**, parce que la règle d'accès et la forme du travail sont les
 * mêmes. Deux classes jumelles divergeraient au premier correctif appliqué à une seule.
 *
 * @implements ProviderInterface<EditorPlan|EditorPlanOption>
 */
final class EditorCatalogProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $this->editorOnly->assertEditor();

        $pourOption = EditorPlanOption::class === $operation->getClass();

        if ($operation instanceof CollectionOperationInterface) {
            return $pourOption ? $this->options() : $this->formules();
        }

        // Création : API Platform réclame un objet à hydrater, sans identifiant.
        if (!isset($uriVariables['id'])) {
            return $pourOption ? new EditorPlanOption() : new EditorPlan();
        }

        return $pourOption
            ? $this->option($this->uuid($uriVariables['id']))
            : $this->formule($this->uuid($uriVariables['id']));
    }

    /** @return list<EditorPlan> */
    private function formules(): array
    {
        /** @var list<Plan> $plans */
        $plans = $this->em->getRepository(Plan::class)->findBy([], ['monthlyPriceCents' => 'ASC']);

        return array_map([$this, 'versFormule'], $plans);
    }

    /** @return list<EditorPlanOption> */
    private function options(): array
    {
        /** @var list<PlanOption> $options */
        $options = $this->em->getRepository(PlanOption::class)->findBy([], ['label' => 'ASC']);

        return array_map([$this, 'versOption'], $options);
    }

    private function formule(Uuid $id): EditorPlan
    {
        $plan = $this->em->getRepository(Plan::class)->find($id);
        if (!$plan instanceof Plan) {
            throw new NotFoundHttpException();
        }

        return $this->versFormule($plan);
    }

    private function option(Uuid $id): EditorPlanOption
    {
        $option = $this->em->getRepository(PlanOption::class)->find($id);
        if (!$option instanceof PlanOption) {
            throw new NotFoundHttpException();
        }

        return $this->versOption($option);
    }

    private function versFormule(Plan $plan): EditorPlan
    {
        $vue = new EditorPlan();
        $vue->id = $plan->getId()->toRfc4122();
        $vue->code = $plan->getCode();
        $vue->label = $plan->getLabel();
        $vue->monthlyPriceCents = $plan->getMonthlyPriceCents();
        $vue->includedCapabilities = array_values($plan->getIncludedCapabilities());
        $vue->active = $plan->isActive();

        return $vue;
    }

    private function versOption(PlanOption $option): EditorPlanOption
    {
        $vue = new EditorPlanOption();
        $vue->id = $option->getId()->toRfc4122();
        $vue->capability = $option->getCapability();
        $vue->label = $option->getLabel();
        $vue->monthlyPriceCents = $option->getMonthlyPriceCents();
        $vue->active = $option->isActive();

        return $vue;
    }

    /**
     * Convertit l identifiant d URL, ou refuse.
     *
     * @cloisonnement-verifie: le catalogue commercial est GLOBAL a l editeur — `Plan` et
     * `PlanOption` ne portent aucun etablissement, il n y a donc pas d etablissement d entite a
     * confronter. Le perimetre est verifie en amont, une fois, par `EditorOnly::assertEditor()` :
     * l appelant est le tenant editeur ou il recoit 404 avant meme d atteindre cette resolution.
     * Un identifiant mal forme ou inexistant rend le meme 404, sans distinction observable.
     */
    private function uuid(mixed $brut): Uuid
    {
        if (!\is_string($brut) || !Uuid::isValid($brut)) {
            // Un identifiant mal formé n'est pas une erreur de saisie à expliquer : c'est une adresse
            // qui ne désigne rien. Même réponse que pour un identifiant inexistant.
            throw new NotFoundHttpException();
        }

        return Uuid::fromString($brut);
    }
}
