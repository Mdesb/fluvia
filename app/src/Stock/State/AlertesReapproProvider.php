<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Stock\ApiResource\AlerteReappro;
use App\Stock\Entity\ArticleStock;
use App\Stock\Service\ArithmetiqueDecimale;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
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
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $qb = $this->em->getRepository(ArticleStock::class)->createQueryBuilder('a')
            ->andWhere('a.actif = true');

        $utilisateur = $this->security->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $qb->innerJoin(
                Affectation::class,
                'aff_alerte',
                Join::WITH,
                'IDENTITY(aff_alerte.etablissement) = IDENTITY(a.etablissement) AND IDENTITY(aff_alerte.utilisateur) = :aff_alerte_utilisateur',
            )->setParameter('aff_alerte_utilisateur', $utilisateur->getId(), 'uuid')->distinct();
        }

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
