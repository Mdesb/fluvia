<?php

declare(strict_types=1);

namespace App\Caisse\State;

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
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SessionCaisse
    {
        $corps = $this->lecteur->corps();

        $pdv = $this->resoudre(PointDeVente::class, $corps['pointDeVente'] ?? null, 'pointDeVente');
        \assert($pdv instanceof PointDeVente);
        $caisse = $this->resoudre(Caisse::class, $corps['caisse'] ?? null, 'caisse');
        \assert($caisse instanceof Caisse);
        $regisseur = $this->resoudre(Utilisateur::class, $corps['regisseur'] ?? null, 'regisseur');
        \assert($regisseur instanceof Utilisateur);

        $code = \is_string($corps['codeRegisseur'] ?? null) ? trim($corps['codeRegisseur']) : '';
        if ($code === '') {
            throw new UnprocessableEntityHttpException('Code régisseur requis à l\'ouverture (RG-M2-01).');
        }

        // CA-2 — une caisse sécurisée exige le code régisseur pour être rouverte (déjà vérifié ci-dessus).
        if ($caisse->getEtat() === EtatCaisse::Securisee && $code === '') {
            throw new UnprocessableEntityHttpException('Caisse sécurisée : code régisseur requis pour rouvrir (CA-2).');
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

        $operateur = $this->security->getUser();
        \assert($operateur instanceof Utilisateur);

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
