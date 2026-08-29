<?php

declare(strict_types=1);

namespace App\Platform\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Platform\Entity\Notification;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * DEUX RESTRICTIONS, ET ELLES NE DISENT PAS LA MÊME CHOSE.
 *
 * **Par destinataire** : une règle de confidentialité. Les notifications d'un collègue ne me
 * regardent pas, même sur un site où j'ai tous les droits — « la facture de Martin SAS est en
 * retard » adressée au dirigeant n'a pas à apparaître chez le caissier.
 *
 * **Par établissement actif** : une règle d'attention. Le périmètre dit ce que j'ai le droit de
 * voir ; l'actif dit ce que je regarde. Une notification d'un site que je ne consulte pas ferait
 * sonner une cloche pour un geste que je ne suis pas en train de faire.
 *
 * ⚠ LES DEUX SONT NÉCESSAIRES, ET AUCUNE NE COUVRE L'AUTRE. Sans la première, la cloche fuit d'un
 * utilisateur à l'autre. Sans la seconde, elle mélange les sites — le défaut constaté le 28/08 sur
 * les tableaux de bord, où un site créé le matin annonçait la session de caisse du voisin.
 *
 * ⚠ ET LE DÉFAUT PAR ABSENCE FERME. Un contexte manquant ne rend pas « tout » : il rend rien. Une
 * liste vide se remarque et se signale ; une liste inter-utilisateurs a seulement l'air plus longue,
 * et personne ne compte les notifications qu'il reçoit.
 */
final class NotificationScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    /**
     * @param array<string, mixed> $identifiers
     * @param array<string, mixed> $context
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== Notification::class) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $utilisateur = $this->security->getUser();
        $actif = $this->contexte->idActif();

        if (!$utilisateur instanceof Utilisateur || $actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.destinataire) = :notification_moi', $alias))
            ->andWhere(sprintf('IDENTITY(%s.etablissement) = :notification_actif', $alias))
            // ⚠ Le type `uuid` est le fond du sujet : l'identifiant est stocké en `BINARY(16)`, et le
            // comparer à une chaîne ne trouve rien — sans lever. Une cloche silencieuse pour cause de
            // liaison non typée est indiscernable d'une cloche sans rien à dire.
            ->setParameter('notification_moi', $utilisateur->getId(), 'uuid')
            ->setParameter('notification_actif', $actif, 'uuid');
    }
}
