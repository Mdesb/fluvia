<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\TypeSupport;
use App\Acces\Hardware\CredentialNormalizer;
use App\Acces\Hardware\EncodingPayload;
use App\Acces\Port\CredentialEncoder;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Remettre un badge ou un bracelet au comptoir (#337) : lire le support posé sur l'encodeur, écrire
 * l'autorisation si le poste le sait, puis l'appairer au droit.
 *
 * ⚠ L'ORDRE EST CELUI QUI NE LAISSE JAMAIS UN SUPPORT À MOITIÉ REMIS. Écrire puis appairer : si
 * l'écriture échoue, rien n'est appairé et l'agent recommence. Dans l'autre sens, un échec
 * d'écriture laisserait un appairage actif sur un bracelet qui n'ouvre rien — et l'appairage suivant
 * serait refusé (« déjà appairé à un droit actif »).
 *
 * Un poste qui ne sait que LIRE (`CredentialEncoding::None`) appaire le numéro de série seul : c'est
 * le cas d'un parc de badges existants, reconnus sans être réencodés.
 */
final class CounterEncodingHandler
{
    public function __construct(
        private readonly CredentialEncoder $encoder,
        private readonly CredentialNormalizer $normalizer,
        private readonly AppairageHandler $pairings,
    ) {
    }

    public function issue(
        string $station,
        DroitAcces $right,
        Etablissement $establishment,
        TypeSupport $type = TypeSupport::Rfid,
        ?int $headcount = null,
        ?Utilisateur $agent = null,
    ): Appairage {
        // Cloisonnement : on ne remet un support que pour un droit de l'établissement du comptoir.
        if ((string) $right->getEtablissement()?->getId() !== (string) $establishment->getId()) {
            throw new NotFoundHttpException('Droit introuvable.');
        }

        $read = $this->encoder->read($station);
        if ($read === null) {
            throw new UnprocessableEntityHttpException('Aucun badge ni bracelet n\'est posé sur l\'encodeur.');
        }

        try {
            $identifier = $this->normalizer->normalize($read->rawIdentifier, $read->format);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException('Lecture du badge illisible : ' . $e->getMessage());
        }

        if ($headcount !== null) {
            try {
                $right->setHeadcount($headcount);
            } catch (\InvalidArgumentException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage());
            }
        }

        if ($this->encoder->encoding() === \App\Acces\Enum\CredentialEncoding::Writes) {
            $result = $this->encoder->write($station, new EncodingPayload(
                $identifier,
                (string) $right->getId(),
                $right->getFenetreFin(),
                $right->getHeadcount(),
            ));
            if (!$result->succes) {
                throw new ConflictHttpException('Encodage refusé par le poste : ' . $result->message);
            }
        }

        // Le nombre de personnes est enregistré par le `flush()` de l'appairage, dans le même geste :
        // un appairage refusé ne laisse pas derrière lui un droit modifié.
        return $this->pairings->appairer($identifier, $type, $right, ModeAppairage::Caisse, $establishment, $agent);
    }
}
