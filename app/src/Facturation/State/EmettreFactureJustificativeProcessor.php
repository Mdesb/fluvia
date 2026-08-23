<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\EmissionFactureJustificativeHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /factures/depuis-vente (US-FACT-01, CA-1/CA-2, `plan-facturation.md` §2). Corps :
 *   { "vente": iri|uuid, "destinataire"?: {...} }
 *
 * @implements ProcessorInterface<mixed, Facture>
 */
final class EmettreFactureJustificativeProcessor implements ProcessorInterface
{
    use LectureReferenceTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly EmissionFactureJustificativeHandler $handler,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Facture
    {
        $corps = $this->lecteur->corps();

        $venteId = $this->uuidDepuis($corps['vente'] ?? null);
        if ($venteId === null) {
            throw new UnprocessableEntityHttpException('Référence de vente obligatoire.');
        }
        $vente = $this->em->getRepository(Vente::class)->find($venteId);
        if ($vente === null) {
            throw new UnprocessableEntityHttpException('Vente introuvable.');
        }

        // D8 — « vente » est un identifiant fourni par le client, et l'operation est en `read: false` :
        // aucune extension de perimetre ne s'applique. `PerimetreVenteExtension` couvre bien `Vente`,
        // mais seulement sur les requetes API Platform Get/GetCollection — pas sur ce `find()` direct.
        //
        // Ce qui rend ce cas plus grave que les cinq precedents : le handler prend l'etablissement
        // **depuis la vente**, pose la facture dessus, et lui attribue un numero de la sequence de cet
        // etablissement-la. Sans ce controle, un agent portant `facturation.emettre_justificative` sur
        // A emettait une facture reelle dans B, consommant un numero de la sequence de B — une
        // sequence que la reglementation impose ininterrompue et infalsifiable. Le dommage n'est donc
        // pas seulement une fuite : il est comptable et fiscal, et il n'est pas annulable en effacant
        // une ligne.
        //
        // Echec ferme en 404 et non 403 : un 403 confirmerait l'existence de la vente ailleurs. Une
        // vente sans etablissement echoue aussi — fermeture par defaut.
        $actif = $this->contexte->etablissementActif();
        if ((string) $vente->getEtablissement()?->getId() !== (string) $actif?->getId()) {
            throw new NotFoundHttpException('Vente introuvable.');
        }

        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $destinataire = \is_array($corps['destinataire'] ?? null) ? $corps['destinataire'] : null;

        return $this->handler->emettre($vente, $destinataire, $auteur);
    }
}
