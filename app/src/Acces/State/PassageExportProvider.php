<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\Entity\Passage;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Export filtré du journal des passages (US-L3-11, écran A-05, CA-12). Filtrable par période
 * (`depuis`/`jusqu'a`), espace, équipement, type (`resultat`) via les paramètres de requête ; lecture
 * seule (aucune mutation), même source que le journal standard.
 *
 * @implements ProviderInterface<list<Passage>>
 */
final class PassageExportProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $this->requestStack->getCurrentRequest();
        // ── CET EXPORT N'ETAIT BORNE PAR RIEN ────────────────────────────────────────────────
        //
        // `PerimetreAccesExtension` ne s'applique qu'aux collections servies par le fournisseur
        // standard d'API Platform : ce `createQueryBuilder` la contourne. Le meme journal, lu par
        // `GET /api/passages`, est cloisonne — c'etait la porte d'entree qui decidait, pas la donnee.
        //
        // Un export est le pire endroit pour cette faute : le fichier s'ouvre, il contient des
        // lignes, elles sont plausibles. Personne ne compte les etablissements dans un CSV, et un
        // export part par courriel et reste sur un poste.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par defaut : mieux vaut un export vide qu'un export du voisin.
            throw new UnprocessableEntityHttpException('Etablissement actif requis (en-tete X-Etablissement).');
        }

        $qb = $this->em->getRepository(Passage::class)->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.etablissement) = :export_etablissement')
            ->setParameter('export_etablissement', $actif, 'uuid')
            ->orderBy('p.horodatage', 'ASC');

        // ⚠ UNE VALEUR ILLISIBLE NE DOIT JAMAIS ÉLARGIR LE RÉSULTAT.
        //
        // La forme précédente était `si présent ET valide, alors filtre` — qui se lit comme une
        // précaution et fait l'inverse : l'appelant demande à RÉDUIRE, et sur une valeur illisible on
        // lui rend TOUT. Dans un export, c'est le pire endroit : le fichier s'ouvre, il contient des
        // lignes, elles sont plausibles, et personne ne compte les lignes d'un CSV.
        //
        // Absent ⇒ pas de filtre : « tout », et c'est une demande légitime. Présent mais illisible ⇒
        // on refuse en NOMMANT le paramètre. On ne répond pas à une question mal comprise en donnant
        // plus que ce qui était demandé.
        if ($request !== null) {
            $depuis = $this->dateOuRefus($request->query->get('depuis'), 'depuis');
            if ($depuis !== null) {
                $qb->andWhere('p.horodatage >= :depuis')->setParameter('depuis', $depuis);
            }
            $jusqua = $this->dateOuRefus($request->query->get('jusqua'), 'jusqua');
            if ($jusqua !== null) {
                $qb->andWhere('p.horodatage <= :jusqua')->setParameter('jusqua', $jusqua);
            }
            $espace = $this->uuidOuRefus($request->query->get('espace'), 'espace');
            if ($espace !== null) {
                $qb->andWhere('IDENTITY(p.espace) = :espace')->setParameter('espace', $espace, 'uuid');
            }
            $equipement = $this->uuidOuRefus($request->query->get('equipement'), 'equipement');
            if ($equipement !== null) {
                $qb->andWhere('IDENTITY(p.equipement) = :equipement')->setParameter('equipement', $equipement, 'uuid');
            }
            if (($resultat = $request->query->get('resultat')) !== null && (string) $resultat !== '') {
                $qb->andWhere('p.resultat = :resultat')->setParameter('resultat', (string) $resultat);
            }
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Un identifiant lisible, ou un refus qui nomme le paramètre.
     *
     * ⚠ La chaîne vide vaut « absent ». Un formulaire qui n'a rien saisi envoie souvent `espace=` :
     * refuser là-dessus ferait échouer un export légitime, alors que l'utilisateur n'a rien demandé.
     */
    private function uuidOuRefus(mixed $valeur, string $parametre): ?string
    {
        if ($valeur === null || (string) $valeur === '') {
            return null;
        }
        if (!Uuid::isValid((string) $valeur)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Le paramètre « %s » n\'est pas un identifiant valide. L\'export a été refusé plutôt que rendu sans ce filtre.',
                $parametre,
            ));
        }

        return (string) $valeur;
    }

    /**
     * Une date lisible, ou un refus qui nomme le paramètre.
     *
     * ⚠ Avant, `new \DateTimeImmutable()` jetait sur une valeur malformée : l'appelant recevait une
     * erreur 500, c'est-à-dire sa propre faute présentée comme une panne du logiciel.
     */
    private function dateOuRefus(mixed $valeur, string $parametre): ?\DateTimeImmutable
    {
        if ($valeur === null || (string) $valeur === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Le paramètre « %s » n\'est pas une date lisible.',
                $parametre,
            ));
        }
    }
}
