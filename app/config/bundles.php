<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Nelmio\CorsBundle\NelmioCorsBundle::class => ['all' => true],
    ApiPlatform\Symfony\Bundle\ApiPlatformBundle::class => ['all' => true],
    Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle::class => ['all' => true],
    // Actif aussi en `prod` : la preproduction tourne avec `APP_ENV=prod`, et sans ce bundle les
    // 37 classes de demonstration n'y sont ni chargeables ni meme autochargeables (elles etendent
    // `Fixture`, qui vient du bundle). C'etait la cause de « la demonstration derive » (T6).
    //
    // ⚠ Ce que cela rend disponible, et ce qui le rend sur : `doctrine:fixtures:load` **purge la
    // base par defaut**, et c'est exactement ce qui a vide les droits des trente-quatre roles de
    // la preproduction le 24/08. Le purgeur est donc neutralise hors `dev`/`test` par
    // `App\Platform\DataFixtures\PurgeurInterditHorsDeveloppement`, et le chargement passe par
    // `app:demo:charger`, qui n'ajoute que ce qui manque.
    Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class => ['all' => true],
];
