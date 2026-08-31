<?php

declare(strict_types=1);

namespace App\Offre\Validator;

use App\Offre\Entity\Saison;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class SaisonSansChevauchementValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SaisonSansChevauchement) {
            throw new UnexpectedValueException($constraint, SaisonSansChevauchement::class);
        }

        if (!$value instanceof Saison) {
            return;
        }

        if (!$value->isActif() || $value->getDateDebut() === null || $value->getDateFin() === null) {
            return;
        }

        // ── LA COMPARAISON EST BORNÉE AU MÊME ÉTABLISSEMENT ──────────────────────────────────
        //
        // Elle ne l'était pas : `findBy(['actif' => true])` confrontait la saison à CELLES DE TOUS
        // LES CLIENTS. Deux conséquences, et la seconde est la pire.
        //
        // Un exploitant ne pouvait pas créer sa saison d'hiver parce qu'un autre client en avait
        // une aux mêmes dates et à la même priorité — un refus incompréhensible, puisque rien dans
        // son écran ne montre la saison qui bloque. Et le message NOMME cette saison : le nom
        // commercial d'un client apparaissait chez un autre, sur un écran de paramétrage.
        //
        // L'entité le disait déjà d'elle-même : « D51 — entièrement cloisonné : l'établissement est
        // estampillé depuis le CONTEXTE ». Le validateur ne le savait pas.
        //
        // `null` se compare à `null` : une saison sans établissement ne se confronte qu'aux autres
        // sans établissement. Les ignorer les rendrait incomparables entre elles, donc autoriserait
        // deux saisons socle superposées — un trou à la place d'une borne.
        // ⚠ À LA CRÉATION, L'ENTITÉ N'EST PAS ENCORE ESTAMPILLÉE. La validation s'exécute AVANT le
        // processeur qui pose l'établissement depuis le contexte (D41) : au POST,
        //  rend , et borner là-dessus comparerait la saison neuve aux
        // seules saisons socle — donc à rien. Deux saisons superposées sur le même site seraient
        // acceptées.
        //
        // Vu en l'écrivant : mes deux premiers tests passaient, le troisième — « la règle continue
        // de s'appliquer chez soi » — tombait. C'est exactement pour ce cas qu'il existe.
        //
        // On lit donc le contexte quand l'entité ne sait pas encore : c'est le même établissement
        // que le processeur posera juste après.
        $etablissement = $value->getEtablissement();
        $idEtablissement = $etablissement?->getId() ?? $this->contexte->idActif();

        $requete = $this->em->getRepository(Saison::class)->createQueryBuilder('s')
            ->andWhere('s.actif = true');

        if ($idEtablissement === null) {
            $requete->andWhere('s.etablissement IS NULL');
        } else {
            // ⚠ `IDENTITY()` et un paramètre TYPÉ (D58) : comparer l'association à l'entité lierait
            // l'identifiant sans son type `uuid`, la requête ne trouverait rien, et le validateur se
            // tairait toujours — sans lever quoi que ce soit.
            $requete
                ->andWhere('IDENTITY(s.etablissement) = :saison_etablissement')
                ->setParameter('saison_etablissement', $idEtablissement, 'uuid');
        }

        /** @var list<Saison> $autres */
        $autres = $requete->getQuery()->getResult();

        foreach ($autres as $autre) {
            if ($autre->getId()->equals($value->getId())) {
                continue;
            }
            if ($autre->getPriorite() !== $value->getPriorite()) {
                continue;
            }
            if ($value->chevauche($autre)) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ saison }}', $autre->getNom())
                    ->atPath('dateDebut')
                    ->addViolation();

                return;
            }
        }
    }
}
