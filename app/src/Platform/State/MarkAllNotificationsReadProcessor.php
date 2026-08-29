<?php

declare(strict_types=1);

namespace App\Platform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Platform\Entity\Notification;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `POST /notifications/tout-lu` — vider la pastille d'un geste.
 *
 * ── POURQUOI CE PROCESSEUR REFAIT LA RESTRICTION AU LIEU DE S'APPUYER SUR L'EXTENSION ───────────
 *
 * ⚠ L'extension Doctrine ne s'applique qu'aux requêtes construites par API Platform. Celle-ci est
 * écrite à la main : rien ne la traverse. C'est exactement le défaut relevé cette semaine sur des
 * fournisseurs sur mesure — « une extension Doctrine ne protège pas un accès écrit à la main » —
 * et il est plus dangereux ici, car il s'agit d'une **écriture** : une restriction oubliée ne
 * montrerait pas des lignes en trop, elle en modifierait.
 *
 * Les deux axes sont donc réécrits ici, à l'identique de l'extension : le destinataire, et
 * l'établissement actif.
 *
 * ── ET L'ÉTABLISSEMENT ACTIF EST DÉLIBÉRÉMENT DANS LA PORTÉE ────────────────────────────────────
 *
 * « Tout marquer comme lu » vide ce que la cloche MONTRE, c'est-à-dire le site que l'on regarde. Un
 * geste qui effacerait aussi les notifications d'un autre site ferait disparaître, sans les avoir
 * affichées, des alertes que personne n'a vues. La cloche et ce bouton doivent compter la même
 * chose, sinon c'est le bouton qu'on croira.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class MarkAllNotificationsReadProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        $actif = $this->contexte->idActif();

        if (!$utilisateur instanceof Utilisateur || $actif === null) {
            // Sans contexte, on n'écrit rien. Le sens sûr de l'erreur est celui qui restreint, et il
            // l'est doublement quand le geste modifie.
            throw new AccessDeniedHttpException('Aucun établissement actif : rien n’a été marqué comme lu.');
        }

        $marquees = $this->em->createQuery(
            'UPDATE '.Notification::class.' n
             SET n.lue = true, n.luLe = :maintenant
             WHERE n.lue = false
               AND IDENTITY(n.destinataire) = :moi
               AND IDENTITY(n.etablissement) = :actif',
        )
            ->setParameter('maintenant', new \DateTimeImmutable())
            // ⚠ Le type `uuid` : sans lui la comparaison porte une chaîne contre une colonne binaire,
            // ne touche aucune ligne, et rend 0 — un « tout est lu » qui n'a rien lu.
            ->setParameter('moi', $utilisateur->getId(), 'uuid')
            ->setParameter('actif', $actif, 'uuid')
            ->execute();

        // Une requête en masse contourne l'unité de travail : les objets déjà chargés dans cette
        // requête porteraient encore `lue = false`. On les écarte plutôt que de les laisser mentir.
        $this->em->clear();

        return new JsonResponse(['marquees' => $marquees]);
    }
}
