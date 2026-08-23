# Plan technique — CQ-1 : recharge d'une carte multi-entrées (`RG-CQ1-01..10`)

- **Spec source :** `specs/acces/spec-cq1-recharge-carte.md`
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Modules touchés :** `App\Vente` (extension, aucune nouvelle route), `App\Acces` (implémentation
  réelle, nouveau service), `COORDINATION/CONTRACT/catalogue-evenements.md` (catalogue)
- **Décisions déjà tranchées, non rouvertes ici :** Option A (recharger = vendre, aucun endpoint ni
  permission neufs), port `App\Vente\Port\CardRechargeInterface` + implémentation réelle
  `App\Acces\Service\CardRechargeHandler`, incrément SQL atomique patron `ValidationPassageHandler`,
  `versionMaj` via `VersionSnapshotSequencer`, D26 défaut (prolongation, une période complète),
  correctif de cohérence sur `StubProjectionDroit` (émission initiale), événement
  `access.card_recharged` au catalogue. Ce plan les **implémente**, il ne les redébat pas.

## 0. Ce qui ne change PAS (rappel, pour cadrer la revue)

- `App\Vente\Entity\Vente.php` — **fichier inchangé**, y compris son attribut `security` sur
  `POST /ventes/{id}/valider` (`vente.encaisser`). Aucun diff dessus.
- `App\Vente\Port\AppairageAccesInterface`/`AppairageAccesStub` — inchangés, non concernés par ce lot.
- Aucune nouvelle route API Platform, aucune nouvelle permission `module.action`.
- Aucune migration de schéma (§4).

## 1. Entités & schéma

**Aucune nouvelle entité, aucune nouvelle colonne.** Les trois champs mobilisés existent déjà :

| Entité | Champ | Type Doctrine | Null | Écriture par ce lot |
|---|---|---|---|---|
| `App\Acces\Entity\DroitAcces` (`acces_droit_acces`) | `creditRestant` | `?int` | oui | `UPDATE ... credit_restant = credit_restant + :n` (RG-CQ1-08), jamais de read-modify-write ORM |
| `App\Acces\Entity\DroitAcces` | `fenetreFin` | `?datetime_immutable` | oui | recalculée à chaque recharge (RG-CQ1-04) **et**, correctif de cohérence, à la première projection (`StubProjectionDroit`, §3 spec pt.4) |
| `App\Acces\Entity\Support` (`acces_support`) | `versionMaj` | `int` (défaut 0) | non | `+ suivant()` via `VersionSnapshotSequencer` (RG-CQ1-03), même patron que `ValidationPassageHandler:199` |
| `App\Vente\Entity\BilletSupport` (`vente_billet_support`) | — | — | — | **jamais modifié** par une recharge (RG-CQ1-05) — reste la trace de l'émission d'origine |
| `App\Vente\Entity\Vente`/`LigneVente` | — | — | — | une recharge = une `Vente`/`LigneVente` standard, scellée NF525 comme aujourd'hui |

Rattachement multi-entités : inchangé — `DroitAcces.etablissement` et `Support.etablissement` restent
la source de cloisonnement côté Accès ; `Vente.etablissement` côté Vente (§3.3).

## 2. API (API Platform)

**Aucune ressource, aucune opération nouvelle.** Le flux réutilisé est `POST /ventes/{id}/valider`
(existant, `App\Vente\State\ValiderVenteProcessor`, `security: is_granted('PERM', 'vente.encaisser')`,
déclaré sur `Vente.php` — hors diff). Le corps `{"supports":[{"ligne": uuid, "identifiant": "…"}]}`
est déjà supporté ; ce qui change est l'**interprétation côté service** d'un `identifiant` déjà connu
(§3).

Les deux fichiers neufs (`CardRechargeInterface`, `CardRechargeHandler`) sont de **simples classes de
service**, sans `#[ApiResource]` ni `security:` — aucune surface API nouvelle, donc aucune ligne
« Ressource / Opérations / security / Groupes / Filtres » à renseigner pour ce lot.

## 3. Conception détaillée

### 3.1 Détection du déclenchement — `App\Vente\Service\ValiderVenteService::creerSupport()`

**Fichier modifié :** `app/src/Vente/Service/ValiderVenteService.php`.

Changement de signature : `creerSupport(LigneVente $ligne, ?array $override): ?BilletSupport` devient
`creerSupport(Vente $vente, LigneVente $ligne, ?array $override): ?BilletSupport` (le seul appelant,
la boucle de `valider()` ligne 64-72, passe `$vente` en plus — `$vente` est déjà dans le scope de
`valider()`). Ce paramètre supplémentaire évite de dépendre de `$ligne->getVente()` (association
bidirectionnelle qui *devrait* être posée mais qu'il est plus sûr de ne pas présumer).

Nouveau bloc, inséré **avant** la construction d'un `BilletSupport` (donc avant toute écriture),
juste après la résolution de `$produit`/`$type`/`emetSupport($type)` (comportement actuel inchangé) :

```php
$identifiantOverride = isset($override['identifiant'])
    && \is_string($override['identifiant'])
    && trim($override['identifiant']) !== ''
        ? trim($override['identifiant'])
        : null;

// RG-CQ1-01 — un identifiant déjà connu, sur une ligne portant une carte multi-entrées, bascule
// l'opération en recharge (Option A, §6 de la spec) au lieu d'une émission. Rien ne change si
// l'identifiant est absent ou inédit (CA-10, non-régression) : on tombe dans le code existant.
if ($identifiantOverride !== null && $produit->getCarte() !== null) {
    $existant = $this->em->getRepository(BilletSupport::class)
        ->findOneBy(['identifiantSupport' => $identifiantOverride]);

    if ($existant instanceof BilletSupport) {
        // RG-CQ1-06 — cloisonnement, échec fermé : un identifiant qui existe mais appartient à un
        // autre établissement échoue COMME s'il n'existait pas (même 404 que AppairageProcessor
        // lignes 79-82) — jamais un oracle cross-tenant, et surtout jamais une tentative d'émission
        // avec un identifiant déjà pris ailleurs (qui crasherait sur la contrainte unique globale,
        // §3 pt.2 de la spec).
        $etabVente = $vente->getEtablissement();
        $etabExistant = $existant->getVente()?->getEtablissement();
        if ($etabVente === null || $etabExistant === null
            || (string) $etabExistant->getId() !== (string) $etabVente->getId()) {
            throw new NotFoundHttpException('Support introuvable.');
        }

        // RG-CQ1-07 (bullet 2) — amélioration de robustesse : conflit explicite au lieu du crash de
        // contrainte unique (§3 pt.2). N'arrive que si l'identifiant scanné est un billet/abonnement,
        // pas une carte.
        if ($existant->getType() !== TypeSupport::Carte) {
            throw new ConflictHttpException(
                'Cet identifiant est déjà utilisé par un support qui n\'est pas une carte multi-entrées.'
            );
        }

        // RG-CQ1-02/08 — crédit ajouté = stock initial du produit VENDU pour cette recharge, même
        // règle que l'émission (ligne 138-140, non multiplié par la quantité — cas limite §10 spec).
        $credits = $produit->getCarte()->getStockCompostagesInitial();
        $this->cardRecharge->recharge($existant, $credits);

        // RG-CQ1-05 — aucun nouveau BilletSupport : la ligne de recharge n'en produit pas (même
        // branche que le cas déjà géré ligne 66-68, `if ($support === null) { continue; }`).
        return null;
    }
    // Identifiant inédit : comportement actuel inchangé, on continue ci-dessous (CA-8).
}
```

Le reste de la méthode (construction du `BilletSupport`, génération d'identifiant, `nbCompostages`)
**n'est pas modifié**.

Constructeur de `ValiderVenteService` : ajouter `private readonly CardRechargeInterface $cardRecharge`
(injection standard, autowire résout via le binding `services.yaml`, §3.4).

Nouveaux imports requis : `App\Vente\Port\CardRechargeInterface`,
`Symfony\Component\HttpKernel\Exception\NotFoundHttpException` (`ConflictHttpException` et
`TypeSupport` sont déjà importés dans ce fichier).

### 3.2 Port — `App\Vente\Port\CardRechargeInterface` (nouveau)

**Fichier neuf :** `app/src/Vente/Port/CardRechargeInterface.php`. Miroir exact de
`AppairageAccesInterface` (RG-CQ1-09) : interface définie côté `App\Vente`, ne référence aucune entité
`App\Acces`.

```php
namespace App\Vente\Port;

use App\Vente\Entity\BilletSupport;

interface CardRechargeInterface
{
    /**
     * Recharge le droit d'accès à crédit appairé à ce support (RG-CQ1-02/03/04/08). Renvoie toujours
     * `true` ou lève une exception HTTP explicite (RG-CQ1-07) — contrairement à
     * `AppairageAccesInterface::appairer()`, il n'existe pas d'échec « doux » ici : chaque refus
     * interrompt la validation de la vente entière (aucune création silencieuse de doublon).
     */
    public function recharge(BilletSupport $support, int $credits): bool;
}
```

### 3.3 Implémentation — `App\Acces\Service\CardRechargeHandler` (nouveau, réel dès ce lot)

**Fichier neuf :** `app/src/Acces/Service/CardRechargeHandler.php`, `implements CardRechargeInterface`.

Dépendances (constructeur, autowire) : `EntityManagerInterface`, `Doctrine\DBAL\Connection`,
`VersionSnapshotSequencer`, `CardExpiryCalculator` (§3.5), `App\Platform\Event\EventBus`,
`Symfony\Bundle\SecurityBundle\Security` (pour l'acteur de l'événement, optionnel).

**Établissement de référence** : dérivé de `$support->getVente()?->getEtablissement()` — **pas** de
`ContexteEtablissement` (en-tête HTTP) relu une seconde fois côté Accès. Le service est donc
appelable hors contexte HTTP (tests unitaires, futur rejeu) et la valeur est déjà celle vérifiée en
`3.1` (RG-CQ1-06).

**Séquence de résolution et refus** (ordre choisi : reprend l'ordre de
`ValidationPassageHandler::valider()` — support inconnu/cloisonné → bloqué → appairage/droit → statut →
type de droit — et **réutilise ses libellés de refus** quand la condition est identique, pour un
vocabulaire cohérent dans tout le module) :

```php
public function recharge(BilletSupport $support, int $credits): bool
{
    $etablissement = $support->getVente()?->getEtablissement();
    if ($etablissement === null) {
        throw new UnprocessableEntityHttpException('Établissement de la vente introuvable.');
    }

    // RG-CQ1-06/07 (bullet « jamais appairée ») — un identifiant sans Support Accès est traité de la
    // même façon qu'un identifiant d'un autre établissement dans `creerSupport()` (échec fermé), mais
    // avec un message distinct : ici on SAIT que la carte est légitime côté Vente (déjà filtrée en
    // 3.1) — le problème est qu'elle n'a jamais été appairée, pas qu'elle appartient à autrui.
    $accesSupport = $this->em->getRepository(Support::class)
        ->findOneBy(['identifiant' => $support->getIdentifiantSupport()]);
    if (!$accesSupport instanceof Support) {
        throw new UnprocessableEntityHttpException(
            'Support jamais appairé côté Accès : finalisez POST /acces/appairages avant de recharger (RG-CQ1-07).'
        );
    }
    // Cloisonnement de sécurité, échec fermé — comme AppairageProcessor lignes 79-82 (défense en
    // profondeur : le cas normal est déjà écarté en 3.1, mais un Support Accès pourrait exister sans
    // qu'aucun BilletSupport ne porte le même identifiant côté Vente si l'appairage a été fait « à la
    // main » avec un identifiant différent — improbable, refusé quand même).
    if ((string) $accesSupport->getEtablissement()?->getId() !== (string) $etablissement->getId()) {
        throw new NotFoundHttpException('Support introuvable.');
    }

    // RG-CQ1-07 (bullet « support bloqué ») — même condition, même message que
    // ValidationPassageHandler::valider() étape 2.
    if ($accesSupport->getStatut() === StatutSupport::Bloque) {
        throw new ConflictHttpException('Support bloqué (perte/vol) : recharge refusée (RG-ACC-07).');
    }

    $appairage = $this->em->getRepository(Appairage::class)
        ->findOneBy(['support' => $accesSupport, 'actif' => true]);
    $droit = $appairage?->getDroit();
    if (!$droit instanceof DroitAcces) {
        // Couvre aussi bien « jamais appairé » que « appairage révoqué » (§3 pt.3 spec) : les deux
        // impliquent qu'il faut repasser par POST /acces/appairages avant de recharger.
        throw new UnprocessableEntityHttpException(
            'Support non appairé à un droit actif : finalisez POST /acces/appairages avant de recharger (RG-CQ1-07).'
        );
    }

    // RG-CQ1-07 (bullet « droit dévalidé ») — même message que ValidationPassageHandler étape 3.
    if ($droit->getStatutProjection() !== StatutProjectionDroit::Valide) {
        throw new ConflictHttpException('Droit dévalidé : recharge refusée (RG-CQ1-07).');
    }

    // RG-CQ1-07 (bullet « sourceType ≠ CarteQuota ») — la recharge ne s'applique qu'aux droits à crédit.
    if ($droit->getSourceType() !== TypeDroitAcces::CarteQuota) {
        throw new ConflictHttpException(
            'Ce droit n\'est pas un droit à crédit (carte) : recharge refusée (RG-CQ1-07).'
        );
    }

    // RG-CQ1-04 — nouvelle échéance, calculée depuis la CarteMultiEntrees du produit VENDU pour cette
    // recharge (résolue depuis BilletSupport → LigneVente → Produit, même lecture que StubProjectionDroit).
    $carte = $this->carteVendue($support);
    $nouvelleEcheance = $carte !== null
        ? $this->cardExpiry->calculer($carte, $droit->getFenetreFin(), new \DateTimeImmutable())
        : $droit->getFenetreFin();

    $droitId = $droit->getId();
    $supportId = $accesSupport->getId();
    $version = $this->sequencer->suivant();

    // RG-CQ1-08 — transaction auto-portée : ce service ne dépend PAS du flush() tardif de
    // ValiderVenteProcessor (appelé bien après, une fois toute la boucle de creerSupport() terminée
    // et la vente scellée). Sans ce wrapping local, le mirage en mémoire (ci-dessous) resterait en
    // attente d'un flush() distant, avec une fenêtre où un décompte concurrent (ValidationPassageHandler)
    // ou une autre recharge pourrait être écrasé par une valeur devenue périmée. Même patron que
    // ValidationPassageHandler lignes 176-264 (raw UPDATE, mirage, flush(), le tout dans un seul
    // `connection->transactional()`).
    $this->connection->transactional(function () use (
        $droit, $accesSupport, $credits, $nouvelleEcheance, $droitId, $supportId, $version
    ): void {
        $this->connection->executeStatement(
            'UPDATE acces_droit_acces SET credit_restant = credit_restant + :n, fenetre_fin = :fin WHERE id = UNHEX(:hex)',
            [
                'n' => $credits,
                'fin' => $nouvelleEcheance?->format('Y-m-d H:i:s'),
                'hex' => bin2hex($droitId->toBinary()),
            ],
        );
        $droit->setCreditRestant(($droit->getCreditRestant() ?? 0) + $credits);
        $droit->setFenetreFin($nouvelleEcheance);

        $this->connection->executeStatement(
            'UPDATE acces_support SET version_maj = :v WHERE id = UNHEX(:hex)',
            ['v' => $version, 'hex' => bin2hex($supportId->toBinary())],
        );
        $accesSupport->setVersionMaj($version);

        $this->em->flush();
    });

    // D7-bis / §7 de la spec — publié APRÈS commit de la transaction locale ci-dessus, jamais depuis
    // ValiderVenteService (qui ne connaît pas la sémantique « recharge »).
    $acteur = $this->security->getUser();
    $this->eventBus->publish(new DomainEvent(
        'access.card_recharged',
        // D6 — tenant dérivé du SUJET (l'établissement du droit), jamais de ContexteEtablissement.
        new EventTenant($droit->getEtablissement()->getId()),
        new EventSubject('DroitAcces', (string) $droit->getId()),
        [
            'droitId' => (string) $droit->getId(),
            'supportId' => (string) $accesSupport->getId(),
            'creditsAdded' => $credits,
            'creditBalanceAfter' => $droit->getCreditRestant(),
            'newExpiryAt' => $nouvelleEcheance?->format(\DATE_ATOM),
            'saleId' => (string) $support->getVente()?->getId(),
        ],
        $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
    ));

    return true;
}

private function carteVendue(BilletSupport $support): ?CarteMultiEntrees
{
    $ligne = $support->getLigne();
    if ($ligne === null) {
        return null;
    }
    $produit = $this->em->getRepository(Produit::class)->find($ligne->getProduit());

    return $produit instanceof Produit ? $produit->getCarte() : null;
}
```

**Points à ne pas rater à l'implémentation :**
- Les deux `executeStatement()` + le `flush()` sont dans le **même** `connection->transactional()` :
  ni les raw `UPDATE`, ni le flush ORM (qui ré-écrit — de façon redondante mais inoffensive, cf. §7 —
  les mêmes colonnes depuis le mirage mémoire) ne doivent en sortir.
- `$droit->setCreditRestant()`/`setFenetreFin()`/`$accesSupport->setVersionMaj()` sont posés **après**
  le raw SQL correspondant, jamais avant (même remarque que `ValidationPassageHandler:195`).
- `EventTenant` est construit depuis `$droit->getEtablissement()`, **jamais** depuis
  `ContexteEtablissement` — c'est le point de sécurité documenté dans `EventTenant.php` (faille
  corrigée le 19/08 sur cinq endpoints) ; une revue doit vérifier cette ligne en particulier.

### 3.4 Câblage DI — `app/config/services.yaml`

Ajouter, dans la section « L3 Contrôle d'accès : ports & adaptateurs » (après la liaison
`ProjectionDroitInterface`, ligne ~61) :

```yaml
    # Recharge de carte multi-entrées (frontière module Accès, US-CQ1, RG-CQ1-09) : implémentation
    # réelle dès ce lot — pas de stub, Accès est propriétaire de DroitAcces/Support/Appairage.
    App\Vente\Port\CardRechargeInterface: '@App\Acces\Service\CardRechargeHandler'
```

Aucune autre entrée `services.yaml` requise : `CardRechargeHandler`, `CardExpiryCalculator` sont
résolus par l'autoconfiguration `App\: resource: '../src/'` (ligne 19-20), pas besoin de `public: true`
(rien ne les appelle directement depuis les tests — les tests passent par l'API, §5).

### 3.5 `App\Acces\Service\CardExpiryCalculator` (nouveau)

**Fichier neuf :** `app/src/Acces/Service/CardExpiryCalculator.php`. Service pur (pas de dépendance
Doctrine), partagé entre `CardRechargeHandler` (recharge) et `StubProjectionDroit` (émission initiale,
§3.6 — bundle de cohérence).

```php
namespace App\Acces\Service;

use App\Offre\Entity\CarteMultiEntrees;

final class CardExpiryCalculator
{
    /**
     * RG-CQ1-04 (D26, défaut) : une période complète depuis `$maintenant`, plafonnée par `dateButoir`
     * si présent. `$fenetreFinActuelle` n'intervient dans AUCUNE branche du calcul par défaut (CA-3 :
     * « J + 1 an », pas « ancienne échéance + 1 an ») — il n'existe que comme point d'extension CQ-7 :
     * « si CarteMultiEntrees::conserverValiditeOrigine() [champ à ajouter par CQ-7] est vrai, retourner
     * $fenetreFinActuelle sans y toucher », en toute première ligne de cette méthode. CQ-7 n'ajoute
     * donc qu'une condition, jamais une réécriture (garantie demandée par la spec §5).
     */
    public function calculer(
        CarteMultiEntrees $carte,
        ?\DateTimeImmutable $fenetreFinActuelle,
        \DateTimeImmutable $maintenant,
    ): ?\DateTimeImmutable {
        $duree = $carte->getValiditeDuree();
        $butoir = $carte->getDateButoir();

        if ($duree === null && $butoir === null) {
            return null; // carte illimitée, comportement actuel inchangé.
        }
        if ($duree === null) {
            return $butoir; // seul le plafond fixe : ne recule jamais à chaque recharge.
        }

        $candidate = $maintenant->add($duree);

        return $butoir !== null ? min($candidate, $butoir) : $candidate;
    }
}
```

### 3.6 Bundle de cohérence — correctif d'émission dans `StubProjectionDroit`

**⚠ Signalé explicitement pour arbitrage de l'intégrateur avant merge** (changement de comportement
observable : les cartes émises avec `validiteDuree`/`dateButoir` renseignés **commencent à expirer**,
alors qu'aujourd'hui elles n'expirent jamais, §3 pt.4 de la spec). Ce n'est pas optionnel pour que
`RG-CQ1-04` soit cohérent (une carte jamais rechargée resterait sinon structurellement sans expiration
alors qu'une carte rechargée une fois en gagnerait une — incohérence pour un même produit), mais c'est
un changement de portée plus large que la seule recharge : **si l'intégrateur préfère différer ce
correctif**, il suffit de ne pas appliquer T5 (§6) — RG-CQ1-01 à 03/05 à 09 restent entièrement
fonctionnels sans lui, seul RG-CQ1-04 reste alors incohérent à l'émission (statu quo actuel).

**Fichier modifié :** `app/src/Acces/Projection/StubProjectionDroit.php`. Injection de
`CardExpiryCalculator` dans le constructeur. Dans `projeter()`, à l'intérieur du bloc
`if ($produit instanceof Produit && $produit->getCarte() !== null)` (lignes 43-47 actuelles),
**uniquement quand `$existant === null`** (première projection, jamais une re-projection) :

```php
$estNouveau = !$existant instanceof DroitAcces;
// ... (inchangé : $droit->setSourceType(CarteQuota)->setCreditRestant(...)->setProduitRef(...))

if ($estNouveau) {
    // Correctif de cohérence (§3 pt.4 de la spec, RG-CQ1-04 appliqué à l'émission) : n'écrit
    // fenetreFin qu'à la PREMIÈRE projection, jamais lors d'une re-projection d'un droit existant
    // (ré-appairage après perte/vol, §1.3 point ouvert n°9) — sinon un simple ré-appairage
    // « réinitialiserait » la validité de la carte, effet de bord non demandé par cette spec.
    $droit->setFenetreFin($this->cardExpiry->calculer($carte, null, new \DateTimeImmutable()));
}
```

`$estNouveau` doit être capturé **avant** la ligne `$droit = $existant instanceof DroitAcces ? $existant : new DroitAcces();` écrase la distinction (capturer `$existant instanceof DroitAcces` dans une
variable dédiée avant cette ligne).

**Effet de bord de test à vérifier** : `OffreFixtures::PRODUIT_CARTE` (carte 10=12) porte déjà
`dateButoir = 2026-12-31` sans `validiteDuree` (`app/src/Offre/DataFixtures/OffreFixtures.php:148-151`).
Après ce correctif, cette carte de démonstration obtient `fenetreFin = 2026-12-31` **dès l'émission**
au lieu de rester `null`. Les tests existants qui la consomment
(`ProjectionDroitTest`, `PaiementTest::testCa12AppairageSupport`, `TerminalSnapshotTest`, etc.)
n'assertent pas `fenetreFin` et exécutent bien avant cette échéance — aucune régression attendue, mais
à confirmer en lançant la suite `App\Tests\Acces`/`App\Tests\Vente` complète (T7).

## 4. Migrations

**Aucune migration.** `credit_restant`, `fenetre_fin`, `version_maj` existent déjà en base (confirmé
§8 de la spec, colonnes déjà mappées sur `DroitAcces`/`Support`). Aucune nouvelle table, aucun nouvel
index.

## 5. Sécurité & droits

- **Aucune nouvelle permission.** `vente.creer`/`vente.encaisser` (existantes, `Vente.php` inchangé)
  couvrent l'intégralité du flux — composer la ligne de recharge, l'encaisser, la valider.
- **Aucun voter nouveau.** Le cloisonnement (RG-CQ1-06) est un contrôle métier explicite dans le code
  (comparaison d'`Etablissement.id`, échec fermé 404), pas une règle de permission — même patron que
  `AppairageProcessor` (qui n'a pas de voter dédié non plus pour son propre IDOR corrigé le 22/08,
  cf. `CloisonnementAppairageDroitTest`).
- `CardRechargeInterface`/`CardRechargeHandler`/`CardExpiryCalculator` : classes de service sans
  attribut `security:` ni `is_granted()` — hors du périmètre du garde-fou de nommage des permissions
  (aucune permission française référencée depuis un fichier neuf, §6 de la spec).

## 6. Tâches (ordonnées)

| # | Tâche | Fichiers | Dépend de |
|---|---|---|---|
| T1 | Créer `App\Acces\Service\CardExpiryCalculator` (§3.5) + tests unitaires purs (5 cas : durée seule, durée+butoir plus proche, durée+butoir plus lointain, butoir seul, aucun des deux) | `app/src/Acces/Service/CardExpiryCalculator.php` | — |
| T2 | Créer `App\Vente\Port\CardRechargeInterface` (§3.2) | `app/src/Vente/Port/CardRechargeInterface.php` | — |
| T3 | Créer `App\Acces\Service\CardRechargeHandler` (§3.3), dépend de T1/T2 | `app/src/Acces/Service/CardRechargeHandler.php` | T1, T2 |
| T4 | Câbler le port dans `services.yaml` (§3.4) | `app/config/services.yaml` | T3 |
| T5 | Étendre `ValiderVenteService::creerSupport()` (§3.1) — signature + `$vente`, détection RG-CQ1-01, cloisonnement, refus type≠Carte, délégation au port, mise à jour de l'unique appelant dans `valider()` | `app/src/Vente/Service/ValiderVenteService.php` | T3, T4 |
| T6 | **Bundle de cohérence** (§3.6, signalé pour arbitrage) — `StubProjectionDroit` applique `CardExpiryCalculator` à la première projection uniquement | `app/src/Acces/Projection/StubProjectionDroit.php` | T1 |
| T7 | Ajouter `access.card_recharged` au catalogue | `COORDINATION/CONTRACT/catalogue-evenements.md` | — (peut être fait en parallèle, avant merge de T3) |
| T8 | Tests fonctionnels/unitaires CA-1..10 (§7) + relance complète `App\Tests\Acces`/`App\Tests\Vente` (non-régression T6) | `app/tests/Acces/Api/CardRechargeTest.php`, `app/tests/Acces/Unit/CardExpiryCalculatorTest.php` | T1-T7 |

T6 est **détachable** : livrable indépendamment (avant ou après T1-T5/T7-T8) si l'intégrateur veut
séquencer le changement de comportement séparément — mais T8 doit alors couvrir les deux configurations
(avec/sans T6) le temps de l'arbitrage.

## 7. Tests

Fixtures : réutiliser `App\Tests\Acces\AccesApiTestCase` (charge déjà Socle + Offre + Vente + Accès,
`app/tests/Acces/AccesApiTestCase.php:50`) — nouveau fichier `App\Tests\Acces\Api\CardRechargeTest`.
`OffreFixtures::PRODUIT_CARTE` (10=12, `dateButoir` seul) suffit pour CA-1/2/5/6/8/9/10 ; CA-3/4
nécessitent un produit-carte ad-hoc (`validiteDuree = P1Y`, avec/sans `dateButoir` à 2 mois) créé en
base directement dans le test, comme `AppairageTest::idDroit2emeSupport()` le fait déjà pour un
`DroitAcces` ad-hoc. **Composition systématique du scénario** (précondition documentée §11 spec) :
1) ouvrir une session caisse, 2) créer une vente, ajouter une ligne produit-carte, payer, valider → une
carte émise (`BilletSupport`), 3) `POST /acces/appairages` avec l'`identifiantSupport` de cette carte
(finalise l'appairage réel, condition sine qua non — §3 pt.3 spec), 4) créer une **seconde** vente,
ajouter la même ligne produit-carte avec `supportsOverride.identifiant` = l'identifiant de l'étape 2,
payer, valider → déclenche la recharge.

| Test | Type | Couvre |
|---|---|---|
| `testCa1MemeDroitIncrementeAucunDoublon` | Fonctionnel API | CA-1 : même `id` de `DroitAcces`, `creditRestant` = 12+12=24 (carte 10=12), compte de lignes `DroitAcces`/`Appairage`/`Support` inchangé (1 chacune) |
| `testCa2VersionMajBasculeEtSnapshotReflete` | Fonctionnel API | CA-2 : `Support.versionMaj` strictement croissant, `GET /terminal/snapshot?depuis=<ancienne>` (via `terminalEntete()`) renvoie le support avec `compostagesRestants` à jour |
| `testCa3EcheanceRepartPourUnePeriodeCompleteDepuisMaintenant` | Fonctionnel API | CA-3 : produit-carte ad-hoc `validiteDuree=P1Y`, `fenetreFin` avant = J+3j → après recharge = J+1an (pas ancienne+1an) |
| `testCa4EcheancePlafonneeParDateButoir` | Fonctionnel API | CA-4 : `validiteDuree=P1Y` + `dateButoir`=J+2mois → `fenetreFin` = `dateButoir`, pas J+1an |
| `testCa5VenteDeRechargeScelleeNf525EtComptabiliseeCa` | Fonctionnel API | CA-5 : la 2ᵉ `Vente` a un numéro, un montant, un `hashChaine` (même patron que `App\Tests\Vente\Api\Nf525ApiTest`), apparaît dans le CA de l'établissement |
| `testCa6CloisonnementRechargeCrossTenantEchoue404` | Fonctionnel API | CA-6 : carte appairée sur A, agent scopé B tente la recharge avec l'identifiant connu → 404 (comme `CloisonnementAppairageDroitTest`), **aucun** champ du droit de A modifié (relecture directe en base après) |
| `testCa7IncrementAtomiqueSansPerteSousEcritureConcurrenteNonSerialisee` | Fonctionnel API/unitaire | CA-7 : technique de preuve — après une 1ʳᵉ recharge légitime en base (crédit initial connu), une écriture SQL brute directe (hors ORM, simulant une seconde recharge concurrente déjà committée, ex. `+5`) est injectée sur la même ligne **avant** de déclencher, via l'API, une recharge applicative (`+3`) sur un `DroitAcces` dont la copie en mémoire (identity map) reste délibérément périmée ; assertion finale en base (après `em->clear()`) : crédit = initial + 5 + 3, jamais initial + 3 (ce qui prouverait un `UPDATE` absolu au lieu du relatif RG-CQ1-08) |
| `testCa8RefusSupportJamaisAppaireAucunDroitCreeEnRepli` | Fonctionnel API | CA-8 : `BilletSupport` type Carte émis mais **sans** `POST /acces/appairages` préalable → 422 explicite, `count(DroitAcces)` inchangé |
| `testCa9RefusSupportBloqueEtDroitDevalide` (2 méthodes) | Fonctionnel API | CA-9 : support `Bloque` (via `POST /acces/supports/{id}/bloquer`) → 409 ; droit `Devalide` (positionné directement en base) → 409 ; solde inchangé dans les deux cas |
| `testCa10NonRegressionEmissionSansOverrideOuIdentifiantInedit` | Fonctionnel API | CA-10 : vente normale (aucun `supportsOverride`, ou identifiant jamais vu) → nouveau `BilletSupport`, `nbCompostages` = stock initial, comportement bit-à-bit identique à `PaiementTest::testCa12AppairageSupport` |
| `testRefusIdentifiantExistantTypeNonCarte` | Fonctionnel API | RG-CQ1-07 bullet 2 : identifiant d'un billet simple existant → 409 explicite (pas de crash de contrainte unique) |
| `CardExpiryCalculatorTest` (5 méthodes) | Unitaire | Les 4 branches de RG-CQ1-04 + non-dépendance à `$fenetreFinActuelle` en dehors du point d'extension |
| `StubProjectionDroitFenetreFinTest` | Fonctionnel API ou unitaire | Bundle de cohérence (T6) : première projection d'une carte `validiteDuree` renseignée → `fenetreFin` non nulle ; **re-projection d'un droit existant** (ré-appairage) → `fenetreFin` **inchangée** (pas de reset) |
| Suite complète `App\Tests\Acces\**`, `App\Tests\Vente\**` | Non-régression | Relancée telle quelle après T5/T6 — en particulier `ProjectionDroitTest`, `PaiementTest`, `AppairageTest`, `CloisonnementAppairageDroitTest`, `TerminalSnapshotTest`, `ValidationPassageTest` |

## 8. Risques / points à valider

1. **Bundle de cohérence (T6) = changement de comportement observable, à arbitrer explicitement**
   (§3.6) — les cartes avec `validiteDuree`/`dateButoir` commencent à expirer dès l'émission. Proposé
   comme faisant partie de ce lot (cohérence RG-CQ1-04 demandée par la spec), mais **détachable** en T6
   si l'intégrateur préfère un commit séparé.
2. **`ValiderVenteService::valider()` n'est pas enveloppée dans une transaction englobante** (chaque
   `executeStatement()`/`flush()` s'auto-commit) — risque **préexistant**, partagé avec
   `DecrementStockHandler` (déjà accepté dans le code actuel, pas introduit par CQ-1). Conséquence pour
   CQ-1 : si un refus survient sur une ligne *suivante* de la même vente, ou si le scellement NF525 échoue
   après la boucle, un `DecrementStockHandler` déjà exécuté (et, désormais, une recharge déjà commitée
   par `CardRechargeHandler`) ne sont pas annulés. C'est pourquoi `CardRechargeHandler` s'auto-encapsule
   dans sa **propre** transaction locale (§3.3) : le crédit ajouté est alors définitivement acquis même
   si la vente échoue plus loin — comportement jugé acceptable (une recharge appliquée pour une vente
   finalement non scellée serait une anomalie visible et rarissime, symétrique au risque déjà accepté
   sur le stock), mais **à confirmer** en revue.
3. **Annulation/avoir d'une vente de recharge** ne retire pas le crédit déjà ajouté — hors périmètre de
   ce lot (`ContrePassationHandler` sait invalider un `BilletSupport` émis via
   `AppairageAccesInterface::invalider()`, rien côté `DroitAcces`). Risque déjà accepté symétriquement
   pour le PMV (`PmvRechargeHandler`, aucune contre-passation dédiée) — signalé, pas résolu ici.
4. **Pas d'historique dédié des recharges** — traçabilité uniquement via les `Vente` successives portant
   une ligne du même produit-carte (jointure par `identifiantSupport`, pas de relation directe). CQ-2
   pourra rouvrir ce point s'il a besoin d'un historique affiché en modale (§9 spec).
5. **`App\Acces` n'implémente aucun `ModuleManifest`** (`grep implements ModuleManifest app/src/Acces`
   → aucun résultat, confirmé). `ManifestCatalogueTest::testLesEvenementsDeclaresFigurentAuCatalogue`
   itère les classes `*Module.php` existantes (`Dms`, `Finance`, `Ocr`) — Accès n'en fait pas partie,
   donc ce test reste un no-op pour `access.card_recharged` : rien n'impose aujourd'hui que le module
   déclare cet événement dans un manifeste. Non bloquant pour ce lot (RG-PLAT-06 ne couvre pas encore
   `App\Acces`), mais à signaler : créer un `AccesModule` est hors périmètre de CQ-1.
6. **`EventSubject::type = 'DroitAcces'`** reste en français — l'entité elle-même n'est pas renommée par
   ce lot (D5 s'applique aux identifiants **neufs** ; `DroitAcces` est un nom de classe déjà en place).
   Tension déjà documentée dans `spec-acc3-projection-reservation.md` §5, assumée à l'identique ici.
7. **Le lookup global de `BilletSupport` par `identifiantSupport` sans jointure établissement (§3.1)
   n'est correct que parce que la contrainte unique est globale** (`uniq_billet_support_identifiant`,
   toute la table, pas par établissement). Si cette contrainte devient un jour composite
   (`identifiant`, `etablissement`), ce point du plan (et le refus RG-CQ1-06) devra être revu — un même
   identifiant pourrait alors légitimement exister dans plusieurs établissements.
8. **`quantite > 1` sur une ligne de recharge n'est pas multiplié** — le crédit ajouté est celui de
   `CarteMultiEntrees::getStockCompostagesInitial()`, indépendamment de `LigneVente::getQuantite()`,
   à l'identique de l'émission (ligne 138-140 actuelle). Écart mineur assumé, documenté §10 de la spec —
   à traiter dans un lot séparé si le besoin « recharger 3 packs en une ligne » est confirmé.
9. **Grignotage de validité (D26, risque assumé, rappel)** : recharger une seule entrée sur une carte à
   validité longue repart pour une période complète. Aucun garde-fou ajouté (minimum de recharge,
   plafond de prolongations cumulées) — décision explicite, pas rouverte ici.
10. **Robustesse du test CA-7 (§7)** dépend du fait que `CardRechargeHandler` ne rafraîchit jamais
    `$droit` (pas de `$em->refresh()`/`clear()` interne) avant son propre `UPDATE` — comportement
    attendu de l'implémentation proposée (§3.3), à vérifier explicitement en revue de code sans quoi le
    test perdrait sa force probante.
