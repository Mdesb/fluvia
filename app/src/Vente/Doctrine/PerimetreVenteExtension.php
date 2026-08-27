<?php

declare(strict_types=1);

namespace App\Vente\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\ClotureZ;
use App\Caisse\Entity\MouvementCaisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\CardRejection;
use App\Vente\Entity\Vente;
use App\Vente\Nf525\Entity\DailyClosure;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources M2 (RG-SOCLE-05) : un utilisateur ne voit que les
 * points de vente, sessions, ventes, avoirs, caisses et mouvements rattachés à un établissement où
 * il possède au moins une affectation. Étend le mécanisme du socle aux entités App\Vente/App\Caisse.
 */
final class PerimetreVenteExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Chemin (relatif à l'alias racine) menant à l'établissement, par classe de ressource.
     *
     * @var array<class-string, string>
     */
    private const CHEMINS = [
        PointDeVente::class => '{root}.etablissement',
        SessionCaisse::class => '{root}.etablissement',
        Vente::class => '{root}.etablissement',
        Avoir::class => '{root}.etablissement',
        Caisse::class => 'pdv.etablissement',
        MouvementCaisse::class => 'sess.etablissement',
        ClotureZ::class => 'sess.etablissement',

        // Ajoutees le 28/08. Elles portaient un `etablissement` depuis toujours, et personne ne
        // s'en servait : une liste blanche ne protege que ce qu'on a pense a y ecrire, et son
        // oubli ne se voit pas -- la collection rend simplement des lignes de plus.
        //
        // Ce que la fuite exposait : les rejets de carte d'un autre etablissement, avec leur
        // identifiant client ; et ses clotures fiscales quotidiennes, c'est-a-dire son chiffre
        // d'affaires jour par jour.
        CardRejection::class => '{root}.etablissement',
        DailyClosure::class => '{root}.etablissement',
    ];

    public function __construct(
        private readonly Security $security,
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
        if (!isset(self::CHEMINS[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        // Jointures intermédiaires éventuelles (caisse → pdv, mouvement/cloture → session).
        if ($resourceClass === Caisse::class) {
            $queryBuilder->innerJoin($rootAlias . '.pointDeVente', 'pdv');
        } elseif ($resourceClass === MouvementCaisse::class || $resourceClass === ClotureZ::class) {
            $queryBuilder->innerJoin($rootAlias . '.session', 'sess');
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMINS[$resourceClass]);

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_vente',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_vente.etablissement) = IDENTITY(%s) AND IDENTITY(aff_perimetre_vente.utilisateur) = :perimetre_vente_utilisateur',
                    $chemin,
                ),
            )
            ->setParameter('perimetre_vente_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
