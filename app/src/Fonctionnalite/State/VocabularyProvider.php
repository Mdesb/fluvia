<?php

declare(strict_types=1);

namespace App\Fonctionnalite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fonctionnalite\ApiResource\Vocabulary;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Fonctionnalite\Service\VocabularyResolver;
use App\Platform\Module\ModuleRegistry;
use App\Securite\Service\ContexteEtablissement;

/**
 * Sert le vocabulaire résolu pour l'établissement courant (#100, option B).
 *
 * Une entrée « default » (le repli FR) + une entrée par verticale qui déclare un vocabulaire dans son
 * manifeste. La verticale de l'établissement — quand il n'en a qu'UNE active — porte `courant = true`,
 * pour que le front en fasse le repli des ressources qui ne portent pas encore leur propre verticale.
 *
 * ⚠ LA LOGIQUE PURE EST EXTRAITE EN MÉTHODES STATIQUES. `ContexteEtablissement` et `Fonctionnalites`
 * sont `final` (non doublables) ; plutôt que de les mocker, on teste directement les trois décisions
 * — quelles verticales, laquelle est unique, comment on assemble — et `provide()` n'est plus qu'un
 * câblage mince entre elles.
 *
 * @implements ProviderInterface<Vocabulary>
 */
final class VocabularyProvider implements ProviderInterface
{
    public function __construct(
        private readonly VocabularyResolver $resolver,
        private readonly ModuleRegistry $registry,
        private readonly Fonctionnalites $fonctionnalites,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<Vocabulary>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $verticales = self::verticalesAvecVocabulaire($this->registry);

        $courante = null;
        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement !== null) {
            $courante = self::verticaleUnique($this->fonctionnalites->actives($etablissement), $verticales);
        }

        return self::assembler($this->resolver, $verticales, $courante);
    }

    /**
     * Les verticales = les modules qui déclarent un vocabulaire. Source de vérité : les manifestes.
     *
     * @return list<string>
     */
    public static function verticalesAvecVocabulaire(ModuleRegistry $registry): array
    {
        $ids = [];
        foreach ($registry->all() as $manifeste) {
            $vocabulary = $manifeste->settingsSchema()['vocabulary'] ?? null;
            if (\is_array($vocabulary) && $vocabulary !== []) {
                $ids[] = $manifeste->id();
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * La verticale de l'établissement, SI il n'en a qu'une active parmi les verticales — sinon `null`
     * (composition ambiguë ou aucune : le front retombe sur le défaut, ou sur la verticale de chaque
     * ressource). Une verticale a pour code de capacité son propre id.
     *
     * @param list<string> $codesActifs
     * @param list<string> $verticales
     */
    public static function verticaleUnique(array $codesActifs, array $verticales): ?string
    {
        $actives = array_values(array_intersect($codesActifs, $verticales));

        return \count($actives) === 1 ? $actives[0] : null;
    }

    /**
     * Assemble les entrées : « default » (repli FR) + une par verticale ; `courant` marque la
     * verticale de l'établissement, ou « default » si aucune n'est unique.
     *
     * @param list<string> $verticales
     *
     * @return list<Vocabulary>
     */
    public static function assembler(VocabularyResolver $resolver, array $verticales, ?string $courante): array
    {
        $defaut = new Vocabulary();
        $defaut->id = 'default';
        $defaut->labels = $resolver->table(null);
        $defaut->courant = $courante === null;

        $entrees = [$defaut];
        foreach ($verticales as $id) {
            $v = new Vocabulary();
            $v->id = $id;
            $v->labels = $resolver->table($id);
            $v->courant = $id === $courante;
            $entrees[] = $v;
        }

        return $entrees;
    }
}
