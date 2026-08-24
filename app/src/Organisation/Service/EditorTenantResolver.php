<?php

declare(strict_types=1);

namespace App\Organisation\Service;

use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Désigne l'établissement qui exploite la plateforme — l'éditeur lui-même (Q-1, claude-D).
 *
 * Le besoin : la souscription commence par un prospect **anonyme** qui compose son panier avant
 * d'avoir la moindre fiche client. Les événements de domaine exigent un établissement porteur, et à
 * ce stade il n'y a rien à déduire — `Client::getEtablissementCreation()` n'existe pas encore.
 * Il faut donc une désignation **explicite**, et c'est la seule façon de la tenir : rien dans le
 * modèle ne distingue l'éditeur d'un client, et c'est très bien ainsi — l'éditeur est un
 * établissement comme un autre, il est seulement *désigné* comme tel par le déploiement.
 *
 * Pourquoi une variable d'environnement plutôt qu'un drapeau sur `Etablissement` : un drapeau
 * `estEditeur` ferait entrer une notion d'exploitation du logiciel dans l'entité pivot du
 * cloisonnement, que trente modules lisent. La désignation appartient au déploiement, pas au métier.
 *
 * ÉCHEC FERME, ET TARDIF. Le service échoue à l'**usage**, jamais au démarrage du conteneur : une
 * variable absente ne doit pas empêcher les huit autres sessions de faire tourner leurs tests sur des
 * modules qui n'ont rien à voir. En revanche il n'y a **aucun repli** — pas de « premier établissement
 * trouvé », pas de valeur par défaut. Un repli silencieux ferait porter les souscriptions de tous les
 * clients par un établissement pris au hasard, et personne ne le verrait avant la facturation.
 */
final class EditorTenantResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(env: 'EDITOR_TENANT_ID')] private readonly string $configuredId = '',
    ) {
    }

    /** L'identifiant configuré, sans toucher la base. Échoue si la désignation manque. */
    public function resolveId(): Uuid
    {
        $brut = trim($this->configuredId);

        if ('' === $brut) {
            throw new \RuntimeException(
                "L'établissement éditeur n'est pas désigné : renseigne EDITOR_TENANT_ID. "
                . "Aucun repli n'est prévu — un établissement choisi au hasard porterait les "
                . "souscriptions de tous les clients sans que personne ne le voie (Q-1, D36)."
            );
        }

        if (!Uuid::isValid($brut)) {
            throw new \RuntimeException(
                "EDITOR_TENANT_ID n'est pas un UUID valide : \"{$brut}\"."
            );
        }

        return Uuid::fromString($brut);
    }

    /** L'établissement éditeur. Échoue si la désignation manque ou ne correspond à rien. */
    public function resolve(): Etablissement
    {
        $id = $this->resolveId();
        $etablissement = $this->entityManager->find(Etablissement::class, $id);

        if (!$etablissement instanceof Etablissement) {
            throw new \RuntimeException(
                "EDITOR_TENANT_ID désigne l'établissement {$id}, qui n'existe pas. "
                . "La désignation vient du déploiement : elle est fausse, ou la base ne correspond pas."
            );
        }

        return $etablissement;
    }

    /** Vrai si l'établissement fourni est l'éditeur. Ne lève pas : sert aux contrôles d'accès. */
    public function isEditor(?Etablissement $etablissement): bool
    {
        if (null === $etablissement) {
            return false;
        }

        try {
            return $etablissement->getId()->equals($this->resolveId());
        } catch (\RuntimeException) {
            // Désignation absente ou invalide : personne n'est l'éditeur. Fermé par défaut.
            return false;
        }
    }
}
