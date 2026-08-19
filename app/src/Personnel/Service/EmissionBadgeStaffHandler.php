<?php

declare(strict_types=1);

namespace App\Personnel\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport as AccesTypeSupport;
use App\Acces\Service\AppairageHandler;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\PorteeAccesEmploye;
use App\Personnel\Entity\RattachementEmploye;
use App\Personnel\Enum\ModeHoraireBadge;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Personnel\Enum\StatutEmploye;
use App\Securite\Entity\Utilisateur;
use App\Vente\Enum\TypeSupport as VenteTypeSupport;
use App\Vente\Service\GenerateurCodeSupport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Émission d'un badge staff (RG-PERSO-06, §2 du plan) : construit **directement** un `DroitAcces`
 * (`sourceType = Personnel`, hors `ProjectionDroitInterface`, spécifique à la projection de vente
 * M1/M2 — non pertinent ici) puis réutilise `App\Acces\Service\AppairageHandler::appairer()` tel
 * quel. **1 badge actif par couple (Employé, Établissement)** (décision n°2 du plan).
 */
final class EmissionBadgeStaffHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppairageHandler $appairageHandler,
        private readonly GenerateurCodeSupport $generateurCode,
        private readonly RecalculFenetreBadgeHandler $recalculFenetre,
    ) {
    }

    /**
     * @param list<EspaceAcces> $espacesAutorises
     */
    public function emettre(
        Employe $employe,
        Etablissement $etablissement,
        ModeHoraireBadge $modeHoraire,
        array $espacesAutorises,
        ?int $margeAvantApres,
        ?Utilisateur $agent = null,
    ): BadgeStaff {
        if ($employe->getStatut() !== StatutEmploye::Actif) {
            throw new UnprocessableEntityHttpException('Un badge ne peut être émis que pour un employé actif (RG-PERSO-06).');
        }

        if (!$this->rattachementActifSurEtablissement($employe, $etablissement)) {
            throw new UnprocessableEntityHttpException('L\'employé n\'a aucun rattachement actif sur cet établissement (RG-PERSO-09) : impossible d\'émettre un badge.');
        }

        $existant = $this->em->getRepository(BadgeStaff::class)->findOneBy([
            'employe' => $employe,
            'etablissement' => $etablissement,
            'statut' => StatutBadgeStaff::Actif,
        ]);
        if ($existant instanceof BadgeStaff) {
            throw new ConflictHttpException('Un badge actif existe déjà pour cet employé sur cet établissement (décision n°2 du plan-personnel).');
        }

        if ($modeHoraire === ModeHoraireBadge::ShiftsUniquement && $margeAvantApres === null) {
            throw new UnprocessableEntityHttpException('La marge avant/après est requise en mode shifts_uniquement.');
        }
        if ($espacesAutorises === []) {
            throw new UnprocessableEntityHttpException('Au moins un espace autorisé est requis (RG-PERSO-06/07).');
        }

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::Personnel)
            ->setEtablissement($etablissement);

        if ($modeHoraire === ModeHoraireBadge::ShiftsUniquement) {
            $droit->setMargeAvanceDefaut($margeAvantApres)->setMargeRetardDefaut($margeAvantApres);
        }
        $this->em->persist($droit);

        $identifiant = $this->generateurCode->genererPourType(VenteTypeSupport::Carte);

        $appairage = $this->appairageHandler->appairer(
            $identifiant,
            AccesTypeSupport::Rfid,
            $droit,
            ModeAppairage::Caisse,
            $etablissement,
            $agent,
        );

        $badge = new BadgeStaff();
        $badge->setEmploye($employe)
            ->setEtablissement($etablissement)
            ->setSupport($appairage->getSupport())
            ->setDroitAcces($droit)
            ->setStatut(StatutBadgeStaff::Actif);
        $this->em->persist($badge);

        $portee = new PorteeAccesEmploye();
        $portee->setBadgeStaff($badge)->setModeHoraire($modeHoraire)->setMargeAvantApres($margeAvantApres);
        foreach ($espacesAutorises as $espace) {
            $portee->addEspaceAutorise($espace);
        }
        $this->em->persist($portee);

        $this->em->flush();

        // Fixe la fenêtre initiale (décision n°3/4) : shift en cours/prochain (± marge) ou aucune
        // contrainte en mode permanent.
        $this->recalculFenetre->recalculer($badge);

        return $badge;
    }

    /** RG-PERSO-09 : un employé sans rattachement actif sur l'établissement ne peut détenir de badge. */
    private function rattachementActifSurEtablissement(Employe $employe, Etablissement $etablissement): bool
    {
        /** @var list<RattachementEmploye> $rattachements */
        $rattachements = $this->em->getRepository(RattachementEmploye::class)->findBy([
            'employe' => $employe,
            'etablissement' => $etablissement,
        ]);

        $maintenant = new \DateTimeImmutable();
        foreach ($rattachements as $rattachement) {
            if ($rattachement->estActifA($maintenant)) {
                return true;
            }
        }

        return false;
    }
}
