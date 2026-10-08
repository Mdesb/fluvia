<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Entity\Facture;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * GET /factures/verifier-chaine?profilExploitant=... (CA-7, intégrité NF525 propre à Facturation).
 * Recalcule la chaîne d'un exploitant et détecte les ruptures (trou de séquence, empreinte altérée,
 * signature invalide). Sans paramètre `profilExploitant`, résout le profil de l'établissement actif.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class VerifierChaineFactureProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementFactureHandler $scellement,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly ContexteEtablissement $contexte,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $profilId = $this->requestStack->getCurrentRequest()?->query->get('profilExploitant');
        $profil = null;
        $etablissement = $this->contexte->etablissementActif();
        if (\is_string($profilId) && Uuid::isValid($profilId)) {
            $profil = $this->em->getRepository(ProfilExploitant::class)->find(Uuid::fromString($profilId));
            // ⚠ D3 : CET IDENTIFIANT VIENT DU CLIENT. Il etait lu tel quel, et n'importe quel lecteur
            // obtenait la chaine — nombre de factures, anomalies — d'un autre exploitant (mesure du
            // 08/10). Un profil qui ne couvre pas l'etablissement actif repond comme un profil absent.
            if (!$profil instanceof ProfilExploitant || $etablissement === null || !$profil->couvre($etablissement)) {
                throw new NotFoundHttpException('Profil exploitant introuvable.');
            }
        } elseif ($etablissement !== null) {
            $profil = $this->comptes->profilPour($etablissement);
        }

        // ⚠ UNE ABSENCE DE PERIMETRE N'EST PAS UNE CHAINE INTACTE.
        //
        // Ce point rendait `['intacte' => true, 'nbDocuments' => 0]` quand le profil ne se resolvait
        // pas : une verification de conformite qui affirme que tout va bien sans avoir rien
        // verifie. C'est la reponse qu'un controleur lirait, et elle serait fausse.
        if (!$profil instanceof ProfilExploitant) {
            throw new UnprocessableEntityHttpException(
                'La chaine n\'a PAS ete verifiee : aucun profil exploitant pour cet etablissement. '
                .'Precisez `?profilExploitant=` ou choisissez un etablissement rattache a un profil.'
            );
        }

        // ⚠ SEULS LES DOCUMENTS SCELLES SONT DANS LA CHAINE.
        //
        // `findBy` sur le seul profil ramenait aussi les BROUILLONS — `numeroSequence = 0`, aucune
        // empreinte. Trie en premier, un brouillon decalait toute la sequence et produisait trois
        // anomalies sur une chaine saine : « trou de sequence : attendu 1, trouve 0 », puis
        // « non verifiable » sur lui-meme, puis « chainage rompu » sur le document suivant.
        //
        // Meme critere que `ScellementFactureHandler::dernierMaillon()`, qui filtre deja ainsi.
        $factures = $this->em->getRepository(Facture::class)->createQueryBuilder('f')
            ->andWhere('IDENTITY(f.profilExploitant) = :profil')
            ->andWhere('f.numeroSequence > 0')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->orderBy('f.numeroSequence', 'ASC')
            ->getQuery()
            ->getResult();
        $rapport = $this->scellement->verifieChaine($factures);

        return new JsonResponse($rapport);
    }
}
