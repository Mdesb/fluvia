<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Equipement;
use App\Acces\Enum\SensPassage;
use App\Acces\Service\OuvertureManuelleHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ouverture manuelle tracée (POST /acces/passages/manuel, US-L3-06, CA-7). Corps :
 *   { "equipement": iri|uuid, "motif": string, "sens"?: "entree"|"sortie" }
 *
 * @implements ProcessorInterface<mixed, \App\Acces\Entity\Passage>
 */
final class PassageManuelProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly OuvertureManuelleHandler $handler,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): \App\Acces\Entity\Passage
    {
        $corps = $this->lecteur->corps();

        $equipementId = $this->uuid($corps['equipement'] ?? null);
        if ($equipementId === null) {
            throw new UnprocessableEntityHttpException('Référence d\'équipement obligatoire.');
        }
        $equipement = $this->em->getRepository(Equipement::class)->find($equipementId);

        // D8 — « equipement » vient du corps de la requete et etait resolu par un `find()` direct, sans
        // aucun controle. La permission `acces.ouvrir_manuel` dit ce que l'agent a le droit de faire,
        // jamais **sur quel equipement**.
        //
        // Signale par claude-C comme **defaut latent**, et la nuance vaut d'etre gardee : aujourd'hui
        // `PiloteAcces` est cable globalement sur le simulateur, donc aucune porte physique ne s'ouvre.
        // Le jour ou un adaptateur reel est branche — c'est l'objet d'ACC-4 — la meme requete ouvre une
        // vraie porte dans un autre etablissement. **La gravite augmente sans que personne ne touche au
        // code**, ce qui est le pire moment pour decouvrir un defaut : personne ne relit un fichier
        // qu'on n'a pas modifie.
        //
        // Echec ferme en 404 : un 403 confirmerait l'existence de l'equipement ailleurs.
        if ($equipement instanceof Equipement
            && (string) $equipement->getEtablissement()?->getId()
               !== (string) $this->contexte->etablissementActif()?->getId()) {
            throw new NotFoundHttpException('Equipement introuvable.');
        }

        if (!$equipement instanceof Equipement) {
            throw new UnprocessableEntityHttpException('Équipement introuvable.');
        }

        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $motif = (string) ($corps['motif'] ?? '');
        $sens = isset($corps['sens']) ? SensPassage::tryFrom((string) $corps['sens']) : SensPassage::Entree;

        return $this->handler->ouvrir($equipement, $agent, $motif, $sens ?? SensPassage::Entree);
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
