<?php

declare(strict_types=1);

namespace App\Autorisation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Service\GestionnaireEscalade;
use App\Autorisation\Service\VerificateurPerimetreLimite;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `POST /demandes-escalade/{id}/approuver` (§4.1/§6.3 plan, RG-AUTZ-06/08/13, CA-3). Revérifie que le
 * superviseur possède `autorisation.approuver` sur `$demande->etablissement` précisément (pas
 * seulement l'établissement actif de l'en-tête).
 *
 * @implements ProcessorInterface<DemandeEscalade, DemandeEscalade>
 */
final class ApprouverEscaladeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GestionnaireEscalade $gestionnaire,
        private readonly VerificateurPerimetreLimite $verificateur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeEscalade
    {
        \assert($data instanceof DemandeEscalade);
        $superviseur = $this->security->getUser();
        \assert($superviseur instanceof Utilisateur);

        $etablissement = $data->getEtablissement();
        if ($etablissement !== null) {
            $this->verificateur->verifier($superviseur, $etablissement, 'approuver');
        }

        $this->gestionnaire->approuver($data, $superviseur);
        $this->em->flush();

        return $data;
    }
}
