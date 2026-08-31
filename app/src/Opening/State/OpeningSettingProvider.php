<?php

declare(strict_types=1);

namespace App\Opening\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organisation\Entity\Etablissement;
use App\Opening\Entity\OpeningSetting;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /ouverture/reglage_ouvertures` — le réglage de l'établissement actif, CRÉÉ S'IL N'EXISTE PAS.
 *
 * ── POURQUOI LA LECTURE CRÉE ────────────────────────────────────────────────────────────────────
 *
 * `OpeningSetting` n'expose ni `Post` ni `Put` : le seul geste possible est de cocher ou décocher
 * la case, c'est-à-dire un `Patch` — qui exige un identifiant. Sans cette création à la lecture,
 * l'écran recevrait une collection vide et n'aurait AUCUN moyen d'activer la règle : le premier
 * exploitant qui essaierait resterait bloqué sans comprendre pourquoi.
 *
 * Créer ne change RIEN au comportement : `applique` naît à `false`, c'est-à-dire exactement ce que
 * l'absence de ligne signifiait déjà. Une ligne inerte de plus, et une case qu'on peut enfin cocher.
 *
 * @implements ProviderInterface<OpeningSetting>
 */
final readonly class OpeningSettingProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContexteEtablissement $contexte,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<OpeningSetting>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Aucun établissement actif.');
        }

        $reglage = $this->em->getRepository(OpeningSetting::class)
            ->findOneBy(['establishment' => $etablissement]);

        if (!$reglage instanceof OpeningSetting) {
            $reglage = (new OpeningSetting())->setEstablishment($etablissement);
            $this->em->persist($reglage);
            $this->em->flush();
        }

        return [$reglage];
    }
}
