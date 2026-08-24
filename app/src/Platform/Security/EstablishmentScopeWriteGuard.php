<?php

declare(strict_types=1);

namespace App\Platform\Security;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Refuse d'écrire une entité rattachée à un établissement hors du périmètre de l'appelant.
 *
 * **Le trou qu'il ferme (D41).** Les extensions Doctrine de ce dépôt bornent les **lectures** au
 * périmètre de l'utilisateur, et elles le font bien — vingt-six modules en ont une. Mais une
 * **écriture** qui reçoit son établissement depuis le corps de la requête n'était vue par personne :
 * ni par les extensions, qui ne s'appliquent qu'aux lectures, ni par le garde-fou de cloisonnement,
 * qui inspecte les résolutions d'entités et non les groupes de sérialisation.
 *
 * Trouvé par `claude-H` sur `PointDeVente`. Compté ensuite : **trente-cinq entités** exposent leur
 * `Etablissement` en écriture sans aucun contrôle, dans douze modules. Quelqu'un pouvait créer une
 * salle dans le musée d'un autre client, un fournisseur chez un concurrent, un bassin dans sa piscine.
 * La victime aurait vu ces objets apparaître dans ses propres écrans, puisque ses lectures, elles, sont
 * filtrées sur son périmètre.
 *
 * **Pourquoi un décorateur global plutôt que trente-cinq processors.** Trente-cinq gardes, ce sont
 * trente-cinq fois la même décision répétée dans douze périmètres, avec la certitude qu'on en
 * oublierait — et que la trente-sixième entité écrite demain ne l'aurait pas. Ici la règle vit à un
 * seul endroit et couvre ce qui n'existe pas encore.
 *
 * **404 et non 403**, comme partout ailleurs dans ce dépôt : un 403 confirmerait à l'appelant que
 * l'établissement qu'il a désigné existe, ce qui en fait un oracle d'énumération.
 *
 * **Une limite assumée, écrite ici pour qu'elle soit visible et non découverte.** Quand aucun
 * utilisateur n'est authentifié — la boutique publique, le tunnel de souscription — le contrôle est
 * **ignoré**, faute de périmètre auquel comparer. Ces points d'entrée doivent donc porter leur propre
 * garde. Refuser par défaut y serait plus sûr en théorie et casserait la vente en ligne en pratique :
 * on préfère une limite nommée à une protection qu'on croit avoir.
 */
#[AsDecorator('api_platform.doctrine.orm.state.persist_processor')]
final class EstablishmentScopeWriteGuard implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $decorated,
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->assertWithinScope($data);

        return $this->decorated->process($data, $operation, $uriVariables, $context);
    }

    private function assertWithinScope(mixed $data): void
    {
        if (!\is_object($data) || !method_exists($data, 'getEtablissement')) {
            return;
        }

        $etablissement = $data->getEtablissement();
        if (!$etablissement instanceof Etablissement) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            // Voir la limite assumée dans l'en-tête de classe : sans utilisateur, pas de périmètre.
            return;
        }

        $affectation = $this->entityManager->getRepository(Affectation::class)->findOneBy([
            'utilisateur' => $utilisateur,
            'etablissement' => $etablissement,
        ]);

        if ($affectation !== null) {
            return;
        }

        // Le message ne nomme pas l'établissement : le donner reviendrait à confirmer son existence.
        throw new NotFoundHttpException('Ressource introuvable.');
    }
}
