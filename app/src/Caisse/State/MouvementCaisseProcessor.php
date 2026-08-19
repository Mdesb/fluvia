<?php

declare(strict_types=1);

namespace App\Caisse\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\MouvementCaisse;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatSession;
use App\Caisse\Enum\TypeMouvement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistre un mouvement d'espèces sur une session (US-L2-10, cahier M2-§8). Un gros retrait
 * (montant ≥ seuil du point de vente) déclenche une alerte régisseur. Corps attendu :
 *   { "session": iri|uuid, "type": "retrait|apport|…", "montant": "100.00", "motif": "…" }
 *
 * @implements ProcessorInterface<mixed, MouvementCaisse>
 */
final class MouvementCaisseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MouvementCaisse
    {
        $corps = $this->lecteur->corps();

        $session = $this->resoudreSession($corps['session'] ?? null);
        if ($session->getEtat() === EtatSession::Close) {
            throw new ConflictHttpException('Session close : aucun mouvement possible.');
        }

        $type = TypeMouvement::tryFrom((string) ($corps['type'] ?? ''));
        if ($type === null) {
            throw new UnprocessableEntityHttpException('Type de mouvement invalide (entree|sortie|apport|retrait|versement).');
        }
        $montant = number_format((float) ($corps['montant'] ?? 0), 2, '.', '');
        if ((float) $montant <= 0) {
            throw new UnprocessableEntityHttpException('Le montant du mouvement doit être strictement positif.');
        }
        $motif = \is_string($corps['motif'] ?? null) ? trim($corps['motif']) : '';
        if ($motif === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour un mouvement de caisse.');
        }

        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $mouvement = (new MouvementCaisse())
            ->setSession($session)
            ->setType($type)
            ->setMontant($montant)
            ->setMotif($motif)
            ->setAuteur($auteur);

        // Alerte gros retrait (cahier M2-§8).
        $seuil = $session->getPointDeVente()?->getSeuilAlerteRetrait();
        if ($type === TypeMouvement::Retrait && $seuil !== null && (float) $montant >= (float) $seuil) {
            $mouvement->setAlerteRegisseur(true);
        }

        $this->em->persist($mouvement);
        $this->em->flush();

        return $mouvement;
    }

    private function resoudreSession(mixed $reference): SessionCaisse
    {
        if (!\is_string($reference) || $reference === '') {
            throw new UnprocessableEntityHttpException('Référence de session obligatoire.');
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;
        if (!Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Référence de session invalide.');
        }
        $session = $this->em->getRepository(SessionCaisse::class)->find(Uuid::fromString($segment));
        if ($session === null) {
            throw new UnprocessableEntityHttpException('Session introuvable.');
        }

        $this->assertSessionDansLePerimetre($session);

        return $session;
    }

    /**
     * Cloisonnement du mouvement d'espèces (D3, D8).
     *
     * L'autorité se recalcule contre l'établissement de la **session visée**, jamais contre l'en-tête
     * `X-Etablissement` : celui-ci est un sélecteur fourni par le client, pas une preuve
     * d'appartenance. C'est la même règle que D6 applique aux événements, appliquée ici en écriture.
     *
     * Ce contrôle ne peut pas être délégué aux extensions Doctrine : elles ne s'exécutent que sur les
     * opérations de **lecture** d'API Platform, or celle-ci est un POST qui résout sa cible depuis le
     * corps de la requête. Sans cette vérification, un utilisateur autorisé sur l'établissement A qui
     * connaît l'UUID d'une session de l'établissement B peut y enregistrer un retrait d'espèces.
     */
    private function assertSessionDansLePerimetre(SessionCaisse $session): void
    {
        $utilisateur = $this->security->getUser();
        $etablissement = $session->getEtablissement();

        $codes = $utilisateur instanceof Utilisateur && $etablissement !== null
            ? $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId())
            : [];

        // Échec fermé. 404 et non 403 : confirmer l'existence d'une session hors périmètre
        // renseignerait déjà l'appelant sur l'activité d'un autre établissement.
        if (!$this->calculateur->autorise($codes, 'caisse', 'mouvement')) {
            throw new NotFoundHttpException('Session introuvable.');
        }
    }
}
