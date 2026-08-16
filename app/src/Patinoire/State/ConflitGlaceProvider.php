<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Patinoire\ApiResource\ConflitGlace;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * GET /patinoire/conflits-glace (US-PATIN-09, RG-PAT-03, décision actée « surbooking de la glace »,
 * CA-9, plan §0 point 5) : interroge en **lecture seule** `App\Reservation\Entity\Creneau` pour
 * détecter les chevauchements sur les `Ressource(codeType='glace')` de l'établissement actif, sans
 * jamais bloquer ni modifier `App\Reservation`. Le moteur générique bloque déjà tout chevauchement à
 * la création standard (`ChevauchementCreneauGuard`) — les conflits ici proviennent donc de créneaux
 * créés **en dehors du moteur standard** (erreur de saisie, correction manuelle, import — cas
 * explicitement évoqué spec §4.8/§7), seul cas où la tolérance RG-PAT-03 s'exprime réellement.
 *
 * @implements ProviderInterface<ConflitGlace>
 */
final class ConflitGlaceProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ConflitGlace
    {
        $vue = new ConflitGlace();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            return $vue;
        }

        /** @var list<Ressource> $ressourcesGlace */
        $ressourcesGlace = $this->em->getRepository(Ressource::class)->findBy([
            'etablissement' => $etablissement,
            'codeType' => 'glace',
        ]);
        if ($ressourcesGlace === []) {
            return $vue;
        }

        foreach ($ressourcesGlace as $ressource) {
            /** @var list<Creneau> $creneaux */
            $creneaux = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
                ->andWhere('c.ressource = :ressource')
                ->andWhere('c.statut != :annule')
                ->setParameter('ressource', $ressource->getId(), 'uuid')
                ->setParameter('annule', StatutCreneau::Annule->value)
                ->orderBy('c.debut', 'ASC')
                ->getQuery()->getResult();

            $n = \count($creneaux);
            for ($i = 0; $i < $n; ++$i) {
                for ($j = $i + 1; $j < $n; ++$j) {
                    $a = $creneaux[$i];
                    $b = $creneaux[$j];
                    if ($a->getDebut() < $b->getFin() && $b->getDebut() < $a->getFin()) {
                        $vue->conflits[] = [
                            'ressource' => (string) $ressource->getId(),
                            'creneauA' => $this->vueCreneau($a),
                            'creneauB' => $this->vueCreneau($b),
                        ];
                    }
                }
            }
        }

        return $vue;
    }

    /** @return array{id: string, debut: string, fin: string, publicReserve: ?string} */
    private function vueCreneau(Creneau $creneau): array
    {
        return [
            'id' => (string) $creneau->getId(),
            'debut' => $creneau->getDebut()->format(DATE_ATOM),
            'fin' => $creneau->getFin()->format(DATE_ATOM),
            'publicReserve' => $creneau->getPublicReserve(),
        ];
    }
}
