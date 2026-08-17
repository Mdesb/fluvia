<?php

declare(strict_types=1);

namespace App\Acces\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DeclarationPerteVol;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\JetonTerminal;
use App\Acces\Entity\JournalReconciliation;
use App\Acces\Entity\ListeRevocation;
use App\Acces\Entity\Passage;
use App\Acces\Entity\Support;
use App\Acces\Entity\Terminal;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources L3 (RG-SOCLE-05) : un utilisateur ne voit que les
 * objets d'accès rattachés à un établissement où il possède au moins une affectation. Étend le
 * mécanisme du socle aux entités App\Acces (même pattern que M2 §7, PerimetreVenteExtension).
 */
final class PerimetreAccesExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, string> Chemin (relatif à l'alias racine) vers l'établissement. */
    private const CHEMINS = [
        EspaceAcces::class => '{root}.etablissement',
        Controleur::class => '{root}.etablissement',
        Equipement::class => '{root}.etablissement',
        Support::class => '{root}.etablissement',
        Appairage::class => '{root}.etablissement',
        DroitAcces::class => '{root}.etablissement',
        Passage::class => '{root}.etablissement',
        DeclarationPerteVol::class => '{root}.etablissement',
        JaugeFmi::class => 'jfmi_esp.etablissement',
        ListeRevocation::class => 'jfmi_ctrl.etablissement',
        // plan-acces-terminal.md §3.3 : ajout additif — Terminal porte des opérations Get/GetCollection
        // administrateur (`/acces/terminaux*`) passant par l'extension standard. JetonTerminal/
        // JournalReconciliation n'ont pas d'opération API exposée dans ce lot (inerte ici, tracé pour
        // cohérence/complétude si un écran de lecture leur est ajouté ultérieurement).
        Terminal::class => '{root}.etablissement',
        JetonTerminal::class => '{root}.etablissement',
        JournalReconciliation::class => '{root}.etablissement',
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

        if ($resourceClass === JaugeFmi::class) {
            $queryBuilder->innerJoin($rootAlias . '.espace', 'jfmi_esp');
        } elseif ($resourceClass === ListeRevocation::class) {
            $queryBuilder->innerJoin($rootAlias . '.controleur', 'jfmi_ctrl');
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMINS[$resourceClass]);

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_acces',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_acces.etablissement) = IDENTITY(%s) AND IDENTITY(aff_perimetre_acces.utilisateur) = :perimetre_acces_utilisateur',
                    $chemin,
                ),
            )
            ->setParameter('perimetre_acces_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
