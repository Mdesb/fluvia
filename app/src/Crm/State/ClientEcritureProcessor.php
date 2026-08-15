<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Création/modification manuelle d'une fiche client (US-L5-02, RG-M4-01/11) : à la création, fixe
 * `groupe`/`etablissementCreation` depuis l'établissement actif ; à la modification, marque les
 * champs de coordonnées écrits comme `champManuel` — l'auto-enrichissement (§ EnrichissementClient
 * Subscriber) ne les écrasera jamais.
 *
 * @implements ProcessorInterface<Client, Client>
 */
final class ClientEcritureProcessor implements ProcessorInterface
{
    private const CHAMPS_COORDONNEES = ['email', 'telephone', 'adresse', 'nom', 'prenom'];

    /**
     * @param ProcessorInterface<Client, Client> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Client);

        $utilisateur = $this->security->getUser();
        $auteur = $utilisateur instanceof Utilisateur ? $utilisateur->getEmail() : null;

        if ($data->getEtablissementCreation() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement instanceof Etablissement) {
                $data->setEtablissementCreation($etablissement);
                if ($data->getGroupe() === null) {
                    $data->setGroupe($etablissement->getRegion()?->getGroupe());
                }
            }
        }

        // Toute coordonnée écrite manuellement via l'API est marquée (RG-M4-01/11) : ne sera jamais
        // écrasée par un enrichissement automatique issu d'une vente M2.
        foreach (self::CHAMPS_COORDONNEES as $champ) {
            $getter = 'get' . ucfirst($champ);
            if ($data->$getter() !== null) {
                $data->marquerChampManuel($champ);
            }
        }

        $data->setDateMaj(new \DateTimeImmutable());
        $data->setMajPar($auteur);
        if ($utilisateur instanceof Utilisateur && $data->getCreePar() === null) {
            $data->setCreePar($utilisateur);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
