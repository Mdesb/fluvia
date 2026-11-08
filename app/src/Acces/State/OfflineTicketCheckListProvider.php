<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\ApiResource\OfflineTicketCheck;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Passage;
use App\Acces\Entity\Support;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutSupport;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * La liste locale du contrôle manuel hors connexion (GET /acces/controle-billet/liste-locale, #338).
 *
 * ⚠ LA MÊME RÈGLE QUE LE CONTRÔLE EN LIGNE, ET RIEN DE PLUS. `VerdictBilletHandler` refuse un support
 * bloqué, non appairé, dont le droit n'est pas « valide » ou ne vaut pas sur ce site ; le contrôle
 * manuel refuse ensuite une entrée unique déjà consommée et un crédit à zéro. Une liste plus stricte
 * ferait refuser hors ligne ce que l'agent accepterait en ligne, et réciproquement. Seuls les titres
 * qui PASSERAIENT y figurent : un code absent de la liste est refusé (« inconnu de la liste locale »),
 * ce qui couvre d'un coup les bloqués, les inconnus et les forgés — sans transporter la liste des
 * révoqués, ni la clé qui vérifie les signatures.
 *
 * ⚠ AUCUN IDENTIFIANT EN CLAIR. Le poste reçoit `sha256(sel . identifiant)` et le sel ; il hache le
 * code scanné de la même façon. Copier la base du navigateur ne donne donc pas de quoi imprimer un
 * billet. Le sel est tiré au hasard à la première page et redemandé par le poste pour les suivantes,
 * pour que toutes les pages d'une même liste se comparent au même code.
 *
 * Cloisonnement : uniquement les supports de l'établissement ACTIF (en-tête), filtré ici en clair —
 * un provider écrit main ne passe pas par les extensions Doctrine.
 *
 * @implements ProviderInterface<OfflineTicketCheck>
 */
final class OfflineTicketCheckListProvider implements ProviderInterface
{
    private const TAILLE_DEFAUT = 1000;
    private const TAILLE_MAX = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OfflineTicketCheck
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Etablissement actif requis (en-tete X-Etablissement) : la liste locale est celle d\'un site.');
        }

        $requete = $this->requestStack->getCurrentRequest();
        $page = max(1, (int) ($requete?->query->get('page') ?? 1));
        $tailleDemandee = (int) ($requete?->query->get('taille') ?? self::TAILLE_DEFAUT);
        $taille = $tailleDemandee > 0 ? min($tailleDemandee, self::TAILLE_MAX) : self::TAILLE_DEFAUT;

        $sel = (string) ($requete?->query->get('sel') ?? '');
        if ($page > 1 && preg_match('/^[0-9a-f]{32}$/', $sel) !== 1) {
            throw new UnprocessableEntityHttpException('Le sel de la première page est requis pour les suivantes.');
        }
        if ($page === 1) {
            $sel = bin2hex(random_bytes(16));
        }

        /** @var list<Appairage> $appairages */
        $appairages = $this->em->getRepository(Appairage::class)->createQueryBuilder('a')
            ->select('a', 's', 'd')
            ->innerJoin('a.support', 's')
            ->innerJoin('a.droit', 'd')
            ->andWhere('a.actif = true')
            ->andWhere('s.etablissement = :etab')->setParameter('etab', $etablissement->getId(), 'uuid')
            ->andWhere('s.statut != :bloque')->setParameter('bloque', StatutSupport::Bloque->value)
            ->andWhere('d.statutProjection = :valide')->setParameter('valide', StatutProjectionDroit::Valide->value)
            ->orderBy('a.id', 'ASC')
            ->setFirstResult(($page - 1) * $taille)
            ->setMaxResults($taille + 1)
            ->getQuery()
            ->getResult();

        $pageSuivante = \count($appairages) > $taille;
        if ($pageSuivante) {
            array_pop($appairages);
        }

        $consommes = $this->dernierControleValide($appairages);

        $vue = new OfflineTicketCheck();
        $vue->genereeLe = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $vue->sel = $sel;
        $vue->page = $page;
        $vue->pageSuivante = $pageSuivante;

        foreach ($appairages as $appairage) {
            $support = $appairage->getSupport();
            $droit = $appairage->getDroit();
            if (!$support instanceof Support || !$droit instanceof DroitAcces || !$droit->isValidAt($etablissement)) {
                continue;
            }
            $credit = $droit->getCreditRestant();
            // Un crédit à zéro serait refusé en ligne : il n'a rien à faire dans une liste de titres qui passent.
            if ($credit !== null && $credit <= 0) {
                continue;
            }
            $vue->entrees[] = [
                'empreinte' => hash('sha256', $sel . $support->getIdentifiant()),
                'credit' => $credit,
                // Entrée unique déjà consommée : gardée dans la liste pour que l'écran dise « déjà
                // contrôlé à telle heure » comme en ligne, au lieu d'un « inconnu » qui tromperait l'agent.
                'dejaControleLe' => $credit === null ? ($consommes[(string) $support->getId()] ?? null) : null,
            ];
        }

        return $vue;
    }

    /**
     * L'heure du dernier contrôle valide des ENTRÉES UNIQUES de la page — une requête, pas une par
     * support. Les cartes à crédit en sont exclues : elles passent plusieurs fois, et leurs passages
     * seraient nombreux à charger pour rien.
     *
     * ⚠ `findBy` ET NON UN `IN` EN DQL : sur une association vers une entité à UUID binaire, un
     * paramètre mal typé rend zéro ligne sans erreur — et zéro ici voudrait dire « jamais contrôlé ».
     *
     * @param list<Appairage> $appairages
     *
     * @return array<string, string> id du support => ISO 8601
     */
    private function dernierControleValide(array $appairages): array
    {
        $supports = [];
        foreach ($appairages as $appairage) {
            $support = $appairage->getSupport();
            if ($support instanceof Support && $appairage->getDroit()?->getCreditRestant() === null) {
                $supports[] = $support;
            }
        }
        if ($supports === []) {
            return [];
        }

        $parSupport = [];
        foreach ($this->em->getRepository(Passage::class)->findBy(['support' => $supports, 'resultat' => ResultatPassage::Valide]) as $passage) {
            $id = (string) $passage->getSupport()?->getId();
            $heure = $passage->getHorodatage()->format(\DATE_ATOM);
            if (!isset($parSupport[$id]) || $heure > $parSupport[$id]) {
                $parSupport[$id] = $heure;
            }
        }

        return $parSupport;
    }
}
