<?php

declare(strict_types=1);

namespace App\Caisse\Validator;

use App\Vente\Tpe\BridgeOutcomeAuthenticator;
use App\Vente\Tpe\LocalBridgeTerminal;
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
        if ($connecteur === LocalBridgeTerminal::CONNECTOR) {
            $cle = $value['clePont'] ?? null;
            if (!\is_string($cle) || BridgeOutcomeAuthenticator::decodeKey($cle) === null) {
                $this->context->buildViolation('Le pont du terminal demande sa clé (« clePont ») : collez celle qu\'affiche « fluvia-bridge init » sur le poste de caisse.')->addViolation();
            }
        }
        // Le pont de caisse sert aussi au tiroir, quel que soit le terminal : son adresse est celle du
        // poste lui-même, jamais une machine du réseau (le navigateur n'y enverrait rien d'autre).
        $pont = $value['pont'] ?? null;
        if ($pont !== null && (!\is_string($pont) || preg_match('#^http://(127\.0\.0\.1|localhost):\d{2,5}$#', $pont) !== 1)) {
            $this->context->buildViolation('L\'adresse du pont est celle du poste lui-même : « http://127.0.0.1:8765 ».')->addViolation();
        }
        if (isset($value['tiroir']) && !\is_bool($value['tiroir'])) {
            $this->context->buildViolation('« tiroir » vaut vrai ou faux.')->addViolation();
        }
        foreach (['marque', 'ref'] as $cle) {
            if (isset($value[$cle]) && (!\is_string($value[$cle]) || mb_strlen($value[$cle]) > 64)) {
                $this->context->buildViolation(sprintf('« %s » est un texte de 64 caractères au plus.', $cle))->addViolation();
            }
        }
    }
}
