<?php

declare(strict_types=1);

namespace App\Caisse\Validator;

use App\Vente\Tpe\TerminalRegistry;
use App\Vente\Tpe\TpeMock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Voir {@see TerminalSettings}. Les messages s'adressent à qui règle le point de vente : ils disent
 * quoi mettre, pas seulement ce qui ne va pas.
 */
final class TerminalSettingsValidator extends ConstraintValidator
{
    public function __construct(
        #[Autowire('%env(bool:TPE_SIMULE_AUTORISE)%')]
        private readonly bool $simulationAutorisee = false,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TerminalSettings) {
            throw new UnexpectedTypeException($constraint, TerminalSettings::class);
        }
        if ($value === null) {
            return;
        }

        $connecteur = \is_array($value) && !array_is_list($value) ? ($value['connecteur'] ?? null) : null;
        if (!\is_string($connecteur) || !\in_array($connecteur, TerminalRegistry::KNOWN, true)) {
            $this->context->buildViolation(sprintf(
                'Le terminal de paiement se règle par { connecteur : %s }, ou se laisse vide (terminal externe : le caissier déclare le résultat).',
                implode(' | ', TerminalRegistry::KNOWN),
            ))->addViolation();

            return;
        }
        if (!\in_array($connecteur, TerminalRegistry::AVAILABLE, true)) {
            $this->context->buildViolation(sprintf('Le connecteur « %s » n\'est pas encore disponible : choisissez « externe ».', $connecteur))->addViolation();

            return;
        }
        if ($connecteur === TpeMock::CONNECTOR && !$this->simulationAutorisee) {
            $this->context->buildViolation('Le terminal simulé est réservé aux démonstrations et aux tests : il accepterait une carte que personne n\'a présentée. Choisissez « externe ».')->addViolation();

            return;
        }
        foreach (['marque', 'ref'] as $cle) {
            if (isset($value[$cle]) && (!\is_string($value[$cle]) || mb_strlen($value[$cle]) > 64)) {
                $this->context->buildViolation(sprintf('« %s » est un texte de 64 caractères au plus.', $cle))->addViolation();
            }
        }
    }
}
