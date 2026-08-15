<?php

declare(strict_types=1);

namespace App\Audit\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Capture un instantané des champs SCALAIRES d'une entité (RG-M8-05) — pas les associations,
 * évite la sérialisation d'objets liés/récursion. Utilisé par `AuditWriteSubscriber` pour
 * renseigner `valeurAvant`/`valeurApres` (création/suppression) ; la modification reconstruit ces
 * valeurs depuis le changeset Doctrine (`$uow->getEntityChangeSet()`), voir `normaliserValeur()`.
 */
final class InstantaneEntiteBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $champsExclus champs sensibles à ne jamais exposer (ex. mot de passe)
     *
     * @return array<string, mixed>
     */
    public function capturer(object $entite, array $champsExclus = []): array
    {
        $metadata = $this->em->getClassMetadata($entite::class);
        $valeurs = [];
        foreach ($metadata->getFieldNames() as $champ) {
            if (\in_array($champ, $champsExclus, true)) {
                continue;
            }
            $valeurs[$champ] = $this->normaliserValeur($metadata->getFieldValue($entite, $champ));
        }

        return $valeurs;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @param list<string>                              $champsExclus
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function depuisChangeSet(array $changeSet, array $champsExclus = []): array
    {
        $avant = [];
        $apres = [];
        foreach ($changeSet as $champ => [$ancienneValeur, $nouvelleValeur]) {
            if (\in_array($champ, $champsExclus, true)) {
                continue;
            }
            $avant[$champ] = $this->normaliserValeur($ancienneValeur);
            $apres[$champ] = $this->normaliserValeur($nouvelleValeur);
        }

        return [$avant, $apres];
    }

    public function normaliserValeur(mixed $valeur): mixed
    {
        return match (true) {
            $valeur instanceof \DateTimeInterface => $valeur->format(DATE_ATOM),
            $valeur instanceof \BackedEnum => $valeur->value,
            \is_object($valeur) && method_exists($valeur, '__toString') => (string) $valeur,
            \is_object($valeur) => null,
            \is_array($valeur) => null,
            default => $valeur,
        };
    }
}
