<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Dto\VerdictBillet;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DeclarationPerteVol;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutSupport;
use App\Vente\Service\GenerateurCodeSupport;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « CE BILLET EST-IL VALIDE ? » — la moitié de la validation qui ne demande AUCUN matériel (D86).
 *
 * ⚠ POURQUOI CE SERVICE EXISTE, ET POURQUOI IL N'EST PAS UNE COPIE.
 *
 * `ValidationPassageHandler::valider()` exige un équipement à sa PREMIÈRE ligne, avant tout le
 * reste. Sur un site sans tourniquet il n'existait donc aucun chemin pour contrôler un billet — un
 * billet vendu y était invendable en pratique. Les six contrôles ci-dessous vivaient déjà dans ce
 * handler, dans cet ordre, et ne se servaient de la topologie que pour CONSTRUIRE L'OBJET DE REFUS,
 * jamais pour décider. C'est ce qui rend l'extraction possible sans réécrire le cœur du contrôle
 * d'accès.
 *
 * `ValidationPassageHandler` appelle désormais ce service au lieu de refaire ces contrôles :
 * une seule règle, deux entrées. Le jour où l'une des deux divergerait, un porteur se verrait
 * refuser à la porte ce qu'un agent vient de lui accorder à la main, et personne ne saurait
 * laquelle des deux a raison.
 *
 * CE QUI N'EST PAS ICI, ET C'EST VOULU : la zone déclarée, la jauge FMI, l'anti-passback, les
 * marges d'avance et de retard, les horaires d'ouverture. Toutes ces règles parlent d'une PORTE.
 */
final class VerdictBilletHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenerateurCodeSupport $generateurCode,
    ) {
    }

    /**
     * @param bool $ignorerRevocationSiPosterieure rejeu hors ligne : voir `VerdictBillet::$enConflitRevocation`
     */
    public function evaluer(
        ?string $identifiantSupport,
        bool $ignorerRevocationSiPosterieure = false,
        ?\DateTimeImmutable $horodatage = null,
    ): VerdictBillet {
        if ($identifiantSupport === null || trim($identifiantSupport) === '') {
            return VerdictBillet::refuse(CodeMotifRefus::DroitInvalide, 'Support requis.');
        }

        // Vérification cryptographique (CA-12/RG-ACC-07) : un code au format d'un code de support
        // signé doit porter une signature HMAC valide. Refusé AVANT la résolution en base, pour ne
        // pas distinguer « support inconnu » de « signature invalide » — la différence renseignerait
        // qui cherche des identifiants valides par tâtonnement. Les identifiants historiques (RFID,
        // QR de démonstration) ne portent pas ce format et ne sont pas concernés.
        if ($this->generateurCode->estCodeSigne($identifiantSupport) && !$this->generateurCode->verifier($identifiantSupport)) {
            return VerdictBillet::refuse(CodeMotifRefus::SignatureInvalide, 'Code de support forgé ou altéré (signature invalide).');
        }

        $support = $this->em->getRepository(Support::class)->findOneBy(['identifiant' => $identifiantSupport]);
        if (!$support instanceof Support) {
            return VerdictBillet::refuse(CodeMotifRefus::DroitInvalide, 'Support inconnu.');
        }

        // Support bloqué : le statut local fait foi, en ligne comme hors ligne (RG-ACC-07).
        $enConflit = false;
        if ($support->getStatut() === StatutSupport::Bloque) {
            if ($ignorerRevocationSiPosterieure && $horodatage !== null && $this->revoqueApresPassage($support, $horodatage)) {
                $enConflit = true;
            } else {
                return VerdictBillet::refuse(CodeMotifRefus::SupportBloque, 'Support bloqué (perte/vol).', $support);
            }
        }

        $appairage = $this->em->getRepository(Appairage::class)->findOneBy(['support' => $support, 'actif' => true]);
        if (!$appairage instanceof Appairage) {
            return VerdictBillet::refuse(CodeMotifRefus::DroitInvalide, 'Support non appairé à un droit actif.', $support);
        }

        $droit = $appairage->getDroit();
        if (!$droit instanceof DroitAcces) {
            return VerdictBillet::refuse(CodeMotifRefus::DroitInvalide, 'Droit introuvable.', $support);
        }

        if ($droit->getStatutProjection() !== StatutProjectionDroit::Valide) {
            return VerdictBillet::refuse(CodeMotifRefus::DroitInvalide, 'Droit dévalidé.', $support, $droit);
        }

        return VerdictBillet::valide($support, $droit, $enConflit);
    }

    private function revoqueApresPassage(Support $support, \DateTimeImmutable $horodatagePassage): bool
    {
        $declaration = $this->em->getRepository(DeclarationPerteVol::class)
            ->findOneBy(['support' => $support, 'annulee' => false], ['horodatage' => 'DESC']);

        return $declaration instanceof DeclarationPerteVol && $declaration->getHorodatage() > $horodatagePassage;
    }
}
