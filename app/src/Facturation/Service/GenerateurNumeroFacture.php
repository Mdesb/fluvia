<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\SerieNumerotation;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\PrefixeSerie;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Attribution du numéro **définitif** d'une facture (RG-FACT-01, `plan-facturation.md` §0.3/§1.4).
 *
 * ### Pourquoi un verrou pessimiste
 * L'obligation légale est une séquence **chronologique, continue, sans trou ni doublon** par
 * `(exploitant, exercice, préfixe)`. Deux mécanismes plus légers ont été écartés :
 *  - un `MAX(numero)+1` : deux requêtes concurrentes lisent le même maximum et produisent un
 *    **doublon** ;
 *  - un `AUTO_INCREMENT` / une séquence SGBD : ces compteurs **ne sont pas transactionnels** — un
 *    rollback consomme quand même la valeur, ce qui crée précisément le **trou** interdit.
 *
 * On pose donc un `SELECT … FOR UPDATE` (`LockMode::PESSIMISTIC_WRITE`) sur l'unique ligne
 * `SerieNumerotation` du couple concerné, **à l'intérieur de la transaction d'émission** (ouverte par
 * l'appelant via `wrapInTransaction`). Deux émissions concurrentes se sérialisent donc sur cette ligne
 * et obtiennent des numéros consécutifs ; si l'émission échoue après l'incrément, la transaction est
 * annulée **en bloc** → aucun trou. Un brouillon jamais émis ne passe jamais ici → il ne consomme
 * aucun numéro (CA-7).
 */
final class GenerateurNumeroFacture
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Attribue le numéro définitif à une facture en cours d'émission.
     * **À n'appeler que dans une transaction déjà ouverte** (le verrou n'a pas de sens sinon).
     */
    public function attribuer(Facture $facture): string
    {
        if ($facture->getNumero() !== null) {
            throw new ConflictHttpException('Cette facture porte déjà un numéro définitif : une réémission est interdite (NF525).');
        }

        $profil = $facture->getProfilExploitant();
        $periode = $facture->getPeriode();
        if (!$profil instanceof ProfilExploitant || !$periode instanceof PeriodeComptable) {
            throw new ConflictHttpException('Exploitant et exercice sont requis pour numéroter une facture.');
        }

        $prefixe = $facture->getNature() === NatureFacture::Avoir ? PrefixeSerie::Avoir : PrefixeSerie::Facture;
        $serie = $this->serieVerrouillee($profil, $periode, $prefixe);

        $sequence = $serie->incrementer();
        $exercice = $periode->getDateDebut()->format('Y');

        $numero = sprintf('%s-%s-%05d', $prefixe->value, $exercice, $sequence);
        $facture->setNumero($numero);

        return $numero;
    }

    /** Charge (ou crée) la ligne de compteur, puis la verrouille en écriture jusqu'au commit. */
    private function serieVerrouillee(ProfilExploitant $profil, PeriodeComptable $periode, PrefixeSerie $prefixe): SerieNumerotation
    {
        $repository = $this->em->getRepository(SerieNumerotation::class);
        $criteres = [
            'profilExploitant' => $profil->getId(),
            'periode' => $periode->getId(),
            'prefixe' => $prefixe,
        ];

        $serie = $repository->findOneBy($criteres);
        if (!$serie instanceof SerieNumerotation) {
            $serie = (new SerieNumerotation())
                ->setProfilExploitant($profil)
                ->setPeriode($periode)
                ->setPrefixe($prefixe);
            $this->em->persist($serie);
            // La ligne doit exister en base avant de pouvoir être verrouillée : ce flush reste dans la
            // transaction d'émission ouverte par l'appelant, il n'est donc jamais visible seul.
            $this->em->flush();
        }

        $this->em->lock($serie, LockMode::PESSIMISTIC_WRITE);
        // Le verrou pose le FOR UPDATE ; la relecture garantit qu'on repart de la valeur réellement
        // committée par la transaction concurrente qui vient de libérer la ligne.
        $this->em->refresh($serie);

        return $serie;
    }
}
