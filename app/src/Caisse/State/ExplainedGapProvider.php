<?php

declare(strict_types=1);

namespace App\Caisse\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Caisse\Entity\AlerteEcartCaisse;
use App\Vente\Entity\SettlementCorrection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D46 — renseigne `AlerteEcartCaisse::estExpliquee()` à la lecture.
 *
 * **Le besoin, dit par `claude-H` en construisant l'écran :** sans ce champ, on affiche la liste des
 * écarts, l'utilisateur en explique un, **et la ligne reste**. Le lendemain elle est encore là, avec
 * les nouvelles. C'est exactement la liste qui apprend à son lecteur à l'ignorer — celle que D55
 * interdit. Elle a refusé de livrer l'écran plutôt que de le faire ; ce fournisseur existe pour ça.
 *
 * **Une requête pour toute la page, pas une par ligne.** Le calcul est fait en lot : on collecte les
 * identifiants des alertes rendues, on interroge une fois les corrections qui les désignent, on
 * marque. Un getter qui aurait interrogé la base depuis l'entité aurait produit un N+1 sur l'écran
 * même que ce champ doit rendre utilisable.
 *
 * **Le lien reste unidirectionnel.** `SettlementCorrection.alerteEcartRef` est une référence libre,
 * pas une association : `AlerteEcartCaisse` appartient à `Caisse` et la correction à `Vente`. C'est
 * la correction qui affirme expliquer ; l'alerte n'apprend rien d'elle-même et ne change pas — elle
 * se déclare immuable, et elle le reste.
 *
 * @implements ProviderInterface<AlerteEcartCaisse>
 */
final class ExplainedGapProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private readonly ProviderInterface $collection,
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ProviderInterface $item,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        // `CollectionOperationInterface` plutôt qu'une devinette sur le nom ou le gabarit d'URI :
        // c'est API Platform lui-même qui dit ce qu'est l'opération.
        $source = $operation instanceof CollectionOperationInterface ? $this->collection : $this->item;

        $donnees = $source->provide($operation, $uriVariables, $context);

        if ($donnees instanceof AlerteEcartCaisse) {
            $this->marquer([$donnees]);

            return $donnees;
        }

        if (is_iterable($donnees)) {
            $alertes = [];
            foreach ($donnees as $alerte) {
                if ($alerte instanceof AlerteEcartCaisse) {
                    $alertes[] = $alerte;
                }
            }
            $this->marquer($alertes);
        }

        return $donnees;
    }

    /** @param list<AlerteEcartCaisse> $alertes */
    private function marquer(array $alertes): void
    {
        if ($alertes === []) {
            return;
        }

        // SQL direct avec `UNHEX`, comme `CardRechargeHandler` et `ApplyNoShowCreditIssueHandler` :
        // `alerte_ecart_ref` est une colonne binaire portant un type Doctrine personnalise, et un
        // `IN (:liste)` en DQL n'y convertit pas les valeurs — la requete ne leve pas, elle rend
        // simplement zero. Verifie : le test restait faux avec la version DQL, sans aucune erreur.
        // C'est la troisieme forme du meme piege cette semaine, apres le parametre d'entite non lie
        // et le SearchFilter sur une reference libre.
        $hex = [];
        foreach ($alertes as $alerte) {
            $hex[] = bin2hex($alerte->getId()->toBinary());
        }

        $marqueurs = implode(', ', array_fill(0, \count($hex), 'UNHEX(?)'));
        /** @var list<string> $trouves */
        $trouves = $this->em->getConnection()->executeQuery(
            'SELECT DISTINCT LOWER(HEX(alerte_ecart_ref)) FROM sale_settlement_correction '
            . 'WHERE alerte_ecart_ref IN (' . $marqueurs . ')',
            $hex,
        )->fetchFirstColumn();

        $expliquees = array_flip(array_map('strtolower', $trouves));

        foreach ($alertes as $alerte) {
            $alerte->marquerExpliquee(isset($expliquees[bin2hex($alerte->getId()->toBinary())]));
        }
    }
}
