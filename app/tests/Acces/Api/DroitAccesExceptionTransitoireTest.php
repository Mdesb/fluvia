<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\TypeDroitAcces;
use App\Tests\Acces\AccesApiTestCase;

/**
 * ⚠ CE TEST EST FAIT POUR MOURIR, ET SON ÉCHEC EST UNE BONNE NOUVELLE (D90).
 *
 * D87 rend `DroitAcces::ouvre()` strict : sans zone déclarée, aucune porte. Deux types de source en
 * sont exemptés à titre transitoire — `Personnel` et `Booking` — parce qu'ils n'ont AUCUN moyen de
 * déclarer leurs zones : un badge de personnel n'a pas de produit dont hériter.
 *
 * Ce qui éteint l'exception est une CONDITION, pas une date : la livraison de D88 (les zones d'un
 * badge viennent de la fonction) et de D89 (celles d'une réservation viennent de l'activité).
 *
 * D'où ce contrôle. Le jour où un droit d'un type exempté porte DÉJÀ des zones déclarées, c'est que
 * le mécanisme existe — donc que l'exception n'a plus d'objet, et qu'elle est en train de devenir un
 * permanent que personne ne remesure. Le test échoue alors, et son message dit quoi faire.
 *
 * Sans lui, la constante survivrait à sa raison d'être en silence : c'est exactement ce qui est
 * arrivé au paragraphe de `ValidationPassageHandler` qui justifiait l'ancien défaut ouvert, dont
 * l'argument était mort des semaines avant qu'on s'en aperçoive — et qui, entre-temps, avait servi
 * d'argument dans la discussion qui a mené à D87.
 */
final class DroitAccesExceptionTransitoireTest extends AccesApiTestCase
{
    public function testExemptionTransitoireNaPlusDObjetDesQuUnTypeExempteSaitDeclarerSesZones(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        // Témoin positif : la requête sait trouver des droits. Sans lui, ce test resterait vert le
        // jour où la table est vide ou l'entité renommée, et dirait « exception encore nécessaire »
        // d'un mécanisme qu'il n'interroge plus.
        $tous = $em->getRepository(DroitAcces::class)->findAll();
        self::assertNotEmpty($tous, 'Aucun droit d’accès en base : ce contrôle ne mesure rien.');

        foreach (DroitAcces::TYPES_EXEMPTES_DE_ZONE as $type) {
            foreach ($em->getRepository(DroitAcces::class)->findBy(['sourceType' => $type]) as $droit) {
                self::assertTrue(
                    $droit->getAuthorisedSpaces()->isEmpty(),
                    sprintf(
                        'Un droit de type « %s » porte %d zone(s) déclarée(s) : le mécanisme de '
                        . 'déclaration existe donc pour ce type, et son exemption dans '
                        . 'DroitAcces::TYPES_EXEMPTES_DE_ZONE n’a plus d’objet (D90). Retirez '
                        . '%s::%s de la constante — et si elle devient vide, retirez la constante '
                        . 'et la branche de ouvre() qui la lit.',
                        $type->value,
                        $droit->getAuthorisedSpaces()->count(),
                        TypeDroitAcces::class,
                        $type->name,
                    ),
                );
            }
        }
    }
}
