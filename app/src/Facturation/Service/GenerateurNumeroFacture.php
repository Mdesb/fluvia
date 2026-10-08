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

        // ⚠ L'ACOMPTE PREND LA SERIE DES FACTURES, PAS UNE SERIE A LUI.
        //
        // Une facture d'acompte EST une facture au sens legal : elle porte de la TVA et ouvre le
        // droit a deduction. La placer dans la sequence des factures est correct, et c'est surtout
        // un objet de moins qui peut manquer -- une serie dediee devrait etre creee par quelqu'un,
        // et un etablissement neuf n'en aurait pas.
        $prefixe = $facture->getNature() === NatureFacture::Avoir ? PrefixeSerie::Avoir : PrefixeSerie::Facture;

        // ⚠ LE COMPTEUR SUIT L'ANNEE, PAS LA PERIODE — ET C'EST UN CORRECTIF, PAS UN CHOIX DE STYLE.
        //
        // `PeriodeComptableResolver` cree une periode par MOIS. Verrouiller le compteur sur la
        // periode le rendait mensuel, alors que le numero compose juste en dessous ne porte que
        // l'annee. La premiere facture de chaque nouveau mois reprenait donc le numero de la
        // premiere du mois precedent, et `uniq_facture_numero` la rejetait : plus aucune facture
        // emettable a partir du deuxieme mois, pour tout exploitant.
        //
        // L'annee se lit sur le DEBUT de la periode, comme avant : une periode ne chevauche jamais
        // deux annees (elle va du 1er au dernier jour d'un mois), donc les deux bornes s'accordent.
        $exercice = (int) $periode->getDateDebut()->format('Y');
        $serie = $this->serieVerrouillee($profil, $exercice, $prefixe);

        $sequence = $serie->incrementer();

        $numero = sprintf('%s-%s-%05d', $prefixe->value, $exercice, $sequence);
        $this->refuserNumeroDejaEmisAuMemeSiren($profil, $numero);
        $facture->setNumero($numero);

        return $numero;
    }

    /**
     * ⚠ L'INDEX VOIT L'EXPLOITANT, LA LOI VOIT LE VENDEUR, C'EST-A-DIRE LE SIREN.
     *
     * Deux sites d'une meme societe (deux SIRET) recoivent chacun un profil
     * (`BackfillAccountingProfilesCommand`) ; la preprod en a deux au SIREN 130025265. Leurs deux
     * series rendraient le meme numero au meme vendeur, et `uniq_facture_profil_numero` les laisserait
     * passer. On refuse donc ici, en le disant. Separer leurs series (un prefixe propre a chacune)
     * reste a trancher (`spec-facturation.md` §4.1, point ouvert).
     *
     * Ce n'est pas un verrou : deux premieres emissions simultanees de ces deux profils passeraient.
     */
    private function refuserNumeroDejaEmisAuMemeSiren(ProfilExploitant $profil, string $numero): void
    {
        if ($profil->getSiren() === '') {
            return;
        }

        $deja = (int) $this->em->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(Facture::class, 'f')
            ->join('f.profilExploitant', 'p')
            ->andWhere('f.numero = :numero')
            ->andWhere('p.siren = :siren')
            ->andWhere('p.id <> :profil')
            ->setParameter('numero', $numero)
            ->setParameter('siren', $profil->getSiren())
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        if ($deja > 0) {
            throw new ConflictHttpException(sprintf(
                'Le numéro %s a déjà été émis par un autre exploitant du même SIREN (%s) : un vendeur ne '
                . 'peut pas émettre deux fois le même numéro. Rattachez ce site au profil qui facture déjà.',
                $numero,
                $profil->getSiren(),
            ));
        }
    }

    /** Charge (ou crée) la ligne de compteur, puis la verrouille en écriture jusqu'au commit. */
    private function serieVerrouillee(ProfilExploitant $profil, int $exercice, PrefixeSerie $prefixe): SerieNumerotation
    {
        $repository = $this->em->getRepository(SerieNumerotation::class);
        $criteres = [
            'profilExploitant' => $profil->getId(),
            'exercice' => $exercice,
            'prefixe' => $prefixe,
        ];

        $serie = $repository->findOneBy($criteres);
        if (!$serie instanceof SerieNumerotation) {
            $serie = (new SerieNumerotation())
                ->setProfilExploitant($profil)
                ->setExercice($exercice)
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
