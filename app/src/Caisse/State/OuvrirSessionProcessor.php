<?php

declare(strict_types=1);

namespace App\Caisse\State;

use App\Platform\Security\EstablishmentScopeAsserter;
use App\Securite\Service\EstablishmentReachability;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\Caisse\Enum\EtatSession;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\GenerateurNumero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ouverture d'une session de caisse (RG-M2-01 / CA-1). Exige point de vente, fond de caisse et
 * régisseur (code validé). Refuse une seconde session active sur le même point de vente (unicité).
 * Une caisse sécurisée (post-Z) exige le code régisseur pour être rouverte (CA-2). Corps attendu :
 *   { "pointDeVente": iri|uuid, "caisse": iri|uuid, "fondDeCaisse": "50.00",
 *     "regisseur": iri|uuid, "codeRegisseur": "…" }
 *
 * @implements ProcessorInterface<mixed, SessionCaisse>
 */
final class OuvrirSessionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly \App\Vente\Service\LecteurCorps $lecteur,
        private readonly GenerateurNumero $generateur,
        private readonly Security $security,
        private readonly EstablishmentScopeAsserter $scope,
        private readonly EstablishmentReachability $reachability,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SessionCaisse
    {
        $corps = $this->lecteur->corps();

        $pdv = $this->resoudre(PointDeVente::class, $corps['pointDeVente'] ?? null, 'pointDeVente');
        \assert($pdv instanceof PointDeVente);
        $caisse = $this->resoudre(Caisse::class, $corps['caisse'] ?? null, 'caisse');
        \assert($caisse instanceof Caisse);
        $operateur = $this->security->getUser();
        \assert($operateur instanceof Utilisateur);

        // L'ouverture d'une caisse ne demande PAS de code régisseur : le régisseur par défaut est
        // l'opérateur qui ouvre. Une caisse fermée est en état « securisee », c'est normal — l'ouverture
        // reste une opération courante. Le code régisseur n'est exigé que pour une RÉOUVERTURE forcée
        // d'une caisse bloquée, via l'endpoint dédié `/sessions-caisse/{id}/rouvrir`.
        $regisseur = isset($corps['regisseur'])
            ? $this->resoudre(Utilisateur::class, $corps['regisseur'], 'regisseur')
            : $operateur;
        \assert($regisseur instanceof Utilisateur);

        // ⚠ AUDIT DU 06/09, CONSTAT 5. Point de vente, caisse et régisseur venaient du corps par `find()`
        //   sans être confrontés à personne : on ouvrait une session — donc une chaîne NF525 — sur le
        //   guichet d'un autre client. Le point de vente doit être atteignable par l'opérateur, la caisse
        //   doit être la sienne, et le régisseur doit pouvoir atteindre le site où il répond.
        $etablissement = $this->scope->assertReachable($pdv->getEtablissement());
        if ($caisse->getPointDeVente()?->getId()->equals($pdv->getId()) !== true) {
            throw new UnprocessableEntityHttpException('Cette caisse n\'appartient pas à ce point de vente.');
        }
        if (!$this->reachability->canReachEstablishment($regisseur, $etablissement, new \DateTimeImmutable())) {
            throw new UnprocessableEntityHttpException('Le régisseur désigné n\'est pas rattaché à cet établissement.');
        }

        if (!isset($corps['fondDeCaisse'])) {
            throw new UnprocessableEntityHttpException('Fond de caisse requis à l\'ouverture (US-L2-01).');
        }
        $fond = number_format((float) $corps['fondDeCaisse'], 2, '.', '');
        if ((float) $fond < 0) {
            throw new UnprocessableEntityHttpException('Le fond de caisse doit être positif ou nul.');
        }

        // Unicité : une seule session active par point de vente (CA-1).
        $active = $this->em->getRepository(SessionCaisse::class)->createQueryBuilder('s')
            ->andWhere('s.pointDeVente = :pdv')
            ->andWhere('s.etat != :close')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('close', EtatSession::Close->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($active !== null) {
            throw new ConflictHttpException('Une session est déjà active sur ce point de vente (RG-M2-01).');
        }

        $caisse->setEtat(EtatCaisse::Ouverte);

        $session = (new SessionCaisse())
            ->setNumero($this->generateur->numeroSession($pdv))
            ->setPointDeVente($pdv)
            ->setCaisse($caisse)
            ->setRegisseur($regisseur)
            ->setOperateur($operateur)
            ->setFondDeCaisse($fond)
            ->setEtat(EtatSession::Ouverte)
            ->setEtablissement($pdv->getEtablissement());

        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        if (!\is_string($reference) || $reference === '') {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire.', $champ));
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;
        if (!Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » invalide (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find(Uuid::fromString($segment));
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }
}
