<?php

declare(strict_types=1);

namespace App\Caisse\Validator;

use App\Caisse\Entity\PointDeVente;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class RevenueOfficeWithinTenantValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RevenueOfficeWithinTenant) {
            throw new UnexpectedValueException($constraint, RevenueOfficeWithinTenant::class);
        }

        if (!$value instanceof PointDeVente) {
            return;
        }

        $regie = $value->getRegie();
        // Aucune régie : c'est le cas NORMAL, pas une omission. Un exploitant privé ou un
        // délégataire n'en a pas, et la très grande majorité des guichets n'en aura jamais.
        if ($regie === null) {
            return;
        }

        $etablissement = $value->getEtablissement();
        if ($etablissement === null) {
            // `etablissement` porte déjà `NotNull` : le signaler une seconde fois donnerait deux
            // violations pour un seul défaut, et la seconde parlerait de la régie à tort.
            return;
        }

        $profil = $regie->getProfilExploitant();
        if ($profil === null) {
            $this->context->buildViolation($constraint->message)->atPath('regie')->addViolation();

            return;
        }

        // ⚠ PRINCIPAL **OU** RATTACHÉ, et c'est délibérément plus large que le cloisonnement des
        // LECTURES du module comptable.
        //
        // `AccountingScopeExtension` et `VatRateCatalogProvider` filtrent sur le seul
        // `etablissementPrincipal`. Ici on valide ce qui est PERMIS, pas ce qui est visible : un
        // profil qui couvre plusieurs établissements (`etablissementsRattaches`, le cas d'un
        // groupement de collectivités) tient légitimement une régie pour chacun, et
        // `PerimetreFinanceExtension` emploie déjà ce périmètre-là.
        //
        // Se limiter au principal refuserait un rattachement parfaitement régulier, et le message
        // d'erreur accuserait alors une configuration saine.
        $principal = $profil->getEtablissementPrincipal();
        if ($principal !== null && $principal->getId()->equals($etablissement->getId())) {
            return;
        }

        foreach ($profil->getEtablissementsRattaches() as $rattache) {
            if ($rattache->getId()->equals($etablissement->getId())) {
                return;
            }
        }

        $this->context->buildViolation($constraint->message)->atPath('regie')->addViolation();
    }
}
