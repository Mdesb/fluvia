<?php

declare(strict_types=1);

namespace App\Acces\Hardware;

use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Entity\Passage;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Service\ValidationPassageHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La remontée de passage commune à tous les pilotes (#337) : lecture brute → identifiant normalisé
 * → décision du moteur → décision de porte.
 *
 * Un pilote concret n'a donc que deux choses à écrire : traduire la trame de son matériel en
 * `HardwareRead`, et traduire `GateDecision` en commande d'ouverture. Tout le reste — signature,
 * révocation, zones, horaires, marges, anti-passback, crédit, jauge, titre de groupe — est la même
 * règle que le contrôle en ligne (`ValidationPassageHandler`), jamais une copie.
 *
 * La commande d'ouverture elle-même est émise par `ValidationPassageHandler` via le port
 * `PiloteAcces`, avec le nombre de personnes du titre (`OuvertureContexte::$headcount`).
 */
final class HardwareEventIngestor
{
    public function __construct(
        private readonly ValidationPassageHandler $validation,
        private readonly CredentialNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ingest(HardwareRead $read): GateDecision
    {
        // Retransmission (même clé) : on rend la décision déjà prise, sans recompter personne.
        $existing = $this->em->getRepository(Passage::class)->findOneBy(['cleIdempotence' => $read->idempotencyKey]);
        if ($existing instanceof Passage) {
            return $this->decisionFor($existing);
        }

        try {
            $identifier = $this->normalizer->normalize($read->rawIdentifier, $read->format);
        } catch (\InvalidArgumentException $e) {
            // Une trame illisible n'est pas un passage : rien n'est enregistré, la porte reste fermée.
            return GateDecision::deny(CodeMotifRefus::DroitInvalide->value, 'Lecture illisible : ' . $e->getMessage());
        }

        $passage = $this->validation->valider(new EvenementPassageDto(
            equipementId: $read->equipmentId,
            identifiantSupport: $identifier,
            sens: $read->direction,
            horodatage: $read->readAt,
            cleIdempotence: $read->idempotencyKey,
        ));

        return $this->decisionFor($passage);
    }

    private function decisionFor(Passage $passage): GateDecision
    {
        $id = (string) $passage->getId();
        if ($passage->getResultat() === ResultatPassage::Valide) {
            return GateDecision::grant($passage->getDroit()?->getHeadcount() ?? 1, 'Accès autorisé.', $id);
        }

        return GateDecision::deny(
            $passage->getCodeMotif()?->value ?? CodeMotifRefus::DroitInvalide->value,
            $passage->getMotif() ?? 'Accès refusé.',
            $id,
        );
    }
}
