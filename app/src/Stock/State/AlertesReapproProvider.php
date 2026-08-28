<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\ApiResource\AlerteReappro;
use App\Stock\Entity\ArticleStock;
use App\Stock\Service\ArithmetiqueDecimale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `GET /stock/alertes-reappro` (RG-STOCK-10, §3.3 du plan) : `ArticleStock` actif dont la
 * disponibilité M1 est ≤ `seuilMin`, quantité suggérée = `seuilMax − disponibilité`.
 *
 * @implements ProviderInterface<AlerteReappro>
 */
final class AlertesReapproProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        // Deux corrections ici, et la seconde est la plus grave.
        //
        // L'AXE : une alerte de reapprovisionnement dit « il faut recommander », donc elle designe
        // un site precis. Agregee sur le perimetre, elle melangeait les stocks de plusieurs sites.
        //
        // LE SENS DE L'ERREUR : le filtre n'etait pose que `if ($utilisateur instanceof
        // Utilisateur)`. Sans utilisateur, AUCUN filtre -- la requete rendait le stock de tous les
        // etablissements. Ecrit comme une precaution, se comportant comme une ouverture.
        $utilisateur = $this->security->getUser();
        $actif = $this->contexte->idActif();
        if (!$utilisateur instanceof Utilisateur || $actif === null) {
            return [];
        }

        $qb = $this->em->getRepository(ArticleStock::class)->createQueryBuilder('a')
            ->andWhere('a.actif = true')
            ->andWhere('IDENTITY(a.etablissement) = :aff_alerte_actif')
            ->setParameter('aff_alerte_actif', $actif, 'uuid')
            ->distinct();

        /** @var list<ArticleStock> $articles */
        $articles = $qb->getQuery()->getResult();

        $alertes = [];
        foreach ($articles as $article) {
            $disponibilite = $article->getProduit()?->getStock()?->disponibiliteEffective();
            if ($disponibilite === null) {
                continue;
            }
            if ($disponibilite > ArithmetiqueDecimale::versEntier($article->getSeuilMin(), 3) / 1000) {
                continue;
            }

            $alerte = new AlerteReappro();
            $alerte->articleStock = (string) $article->getId();
            $alerte->libelle = $article->getLibelle();
            $alerte->codeEAN = $article->getCodeEAN();
            $alerte->disponibilite = $disponibilite;
            $alerte->seuilMin = $article->getSeuilMin();
            $alerte->seuilMax = $article->getSeuilMax();
            $alerte->quantiteSuggeree = ArithmetiqueDecimale::soustraire($article->getSeuilMax(), (string) $disponibilite . '.000', 3);
            $alertes[] = $alerte;
        }

        return $alertes;
    }
}
