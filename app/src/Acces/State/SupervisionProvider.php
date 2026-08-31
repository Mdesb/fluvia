<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\ApiResource\Supervision;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\Passage;
use App\Acces\Enum\EtatControleur;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\ResultatPassage;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Vue live agrégée (GET /acces/supervision, US-L3-06, CA-7) : jauges FMI par espace, état réseau des
 * contrôleurs, incidents (refus récents, seuil atteint, contrôleur hors-ligne). Bornée à
 * l'établissement actif (RG-SOCLE-05) — et REFUSE de répondre s'il n'y en a pas, voir plus bas.
 *
 * @implements ProviderInterface<Supervision>
 */
final class SupervisionProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Supervision
    {
        // ⚠ SANS ÉTABLISSEMENT ACTIF, CET ÉCRAN RENDAIT TOUS LES SITES — ET IL SUFFISAIT D'OMETTRE
        // UN EN-TÊTE.
        //
        // `etablissementActif()` rend `null` quand `X-Etablissement` manque : il ne lève pas. Les
        // critères devenaient alors `[]`, et `findBy([])` veut dire « tout ». Mesuré le 30/08 sur la
        // préprod, même jeton, seule l'en-tête retirée :
        //
        //     avec X-Etablissement : 2 contrôleurs, 4 jauges  — le site actif
        //     sans                 : 6 contrôleurs, 8 jauges  — dont « Contrôleur Site B1 »
        //
        // Le cloisonnement de ce dépôt vit dans `PerimetreAccesExtension`, qui ne s'applique qu'aux
        // collections servies par le fournisseur standard. Un provider sur mesure ne la traverse
        // jamais : la borne doit y être écrite à la main, et son absence ne se voit pas — elle
        // s'écrit `!== null`.
        //
        // ⚠ ON REFUSE, ON NE REND PAS UNE VUE VIDE. Une supervision vide se lit « aucun contrôleur »,
        // c'est-à-dire un site en ordre — le mensonge exactement inverse de celui qu'on corrige. Même
        // choix que `PassageExportProvider`. Fermer sur un doute se répare d'un clic ; ouvrir à tort
        // a déjà montré les libellés et les états d'un autre site.
        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException(
                'Etablissement actif requis (en-tete X-Etablissement) : la supervision est bornee a un site.'
            );
        }

        $vue = new Supervision();
        $criteres = ['etablissement' => $etablissement];

        $jaugesQb = $this->em->getRepository(JaugeFmi::class)->createQueryBuilder('j')
            ->innerJoin('j.espace', 'e')
            ->andWhere('IDENTITY(e.etablissement) = :etab')
            ->setParameter('etab', $etablissement->getId(), 'uuid');
        /** @var list<JaugeFmi> $jauges */
        $jauges = $jaugesQb->getQuery()->getResult();

        foreach ($jauges as $jauge) {
            $espace = $jauge->getEspace();
            $vue->jauges[] = [
                'espace' => $espace instanceof EspaceAcces ? (string) $espace->getId() : null,
                'libelle' => $espace?->getLibelle(),
                'valeurCourante' => $jauge->getValeurCourante(),
                'seuil' => $jauge->getSeuil(),
                'cumulJour' => $jauge->getCumulJour(),
                'mode' => $jauge->getMode()->value,
            ];
            if ($jauge->getMode() === ModeSeuil::Alerte && $jauge->getSeuil() > 0 && $jauge->getValeurCourante() >= $jauge->getSeuil()) {
                $vue->incidents[] = ['type' => 'seuil_fmi', 'espace' => $espace?->getLibelle(), 'message' => 'Seuil de jauge FMI atteint (alerte).'];
            }
        }

        /** @var list<Controleur> $controleurs */
        $controleurs = $this->em->getRepository(Controleur::class)->findBy($criteres);
        foreach ($controleurs as $controleur) {
            $vue->controleurs[] = [
                'id' => (string) $controleur->getId(),
                'libelle' => $controleur->getLibelle(),
                'etat' => $controleur->getEtat()->value,
                'dernierHeartbeat' => $controleur->getDernierHeartbeat()?->format(DATE_ATOM),
            ];
            if ($controleur->getEtat() !== EtatControleur::EnLigne) {
                $vue->incidents[] = ['type' => 'reseau', 'controleur' => $controleur->getLibelle(), 'message' => 'Contrôleur ' . $controleur->getEtat()->value . '.'];
            }
        }

        $refus = $this->em->getRepository(Passage::class)->createQueryBuilder('p')
            ->andWhere('p.resultat = :refuse')
            ->setParameter('refuse', ResultatPassage::Refuse)
            ->orderBy('p.horodatage', 'DESC')
            ->setMaxResults(10)
            ->getQuery()->getResult();
        foreach ($refus as $passage) {
            \assert($passage instanceof Passage);
            $vue->incidents[] = [
                'type' => 'refus',
                'passage' => (string) $passage->getId(),
                'motif' => $passage->getMotif(),
                'codeMotif' => $passage->getCodeMotif()?->value,
                'horodatage' => $passage->getHorodatage()->format(DATE_ATOM),
            ];
        }

        return $vue;
    }
}
