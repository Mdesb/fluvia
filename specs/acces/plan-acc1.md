# Plan technique — ACC-1 : échec explicite sur opération de pilote non déclarée + restitution des capacités (`D17`)

- **Spec source :** `specs/acces/spec-acc1-echec-explicite.md`
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Décisions reprises telles quelles (ne pas rouvrir, actées par l'orchestrateur) :**
  - **D-1** : décorateur `App\Acces\Adapter\CapabilityGuardedPiloteAcces implements PiloteAcces`, câblé
    sur l'alias DI `App\Acces\Port\PiloteAcces` ; l'adaptateur réel devient un service interne injecté
    dedans. Les 3 handlers appelants ne changent pas.
  - **D-2** : pas d'événement de domaine `access.capability_gap_detected` dans ce lot.
  - **D-3** : granularité pilote global aujourd'hui ; la ressource de restitution accepte un id de
    `Controleur` (prête pour D17) mais renvoie les mêmes valeurs pour tous.
  - **D-4** : nommage anglais — `UndeclaredCapabilityException` (exception, `App\Acces\Exception`),
    `AccessCapabilities` (ressource de restitution), `access.capability_gap_detected` (action d'audit).
  - **D-5** : permission `acces.superviser` (réutilisée) pour la lecture de la restitution.
  - Le cas limite `ResultatCommande::echec()` ignoré aux call sites `ouvrir()` (spec §8) est **hors
    périmètre** de ce lot — documenté en risque connexe §9, proposé en lot séparé.

## 0. Résumé de l'approche

Trois changements de code, aucune migration :

1. **`CapabilityGuardedPiloteAcces`** (`App\Acces\Adapter`) : décorateur qui enveloppe l'adaptateur réel
   (composition), évalue le mapping opération→capacité de RG-ACC1-01 sur `capabilities()`, écrit une
   `EntreeAudit` et flush **immédiatement** (avant de lever) si la capacité manque, puis délègue. `ouvrir()`
   n'a **aucune** garde de capacité (RG-ACC1-01, ⚠ hypothèse actée) — délégation directe, l'échec « protocole
   non cadré » d'Itbox/SmartAccess reste inchangé (CA-9).
2. **`UndeclaredCapabilityException`** (`App\Acces\Exception`) : porte `operation`, `adapterClass`,
   `missingCapability`, et l'id du `Controleur` cible quand disponible.
3. **`AccessCapabilities`** (`App\Acces\ApiResource`, non-Doctrine) + `AccessCapabilitiesProvider`
   (`App\Acces\State`) : restitue, pour chaque `Controleur` visible par l'établissement actif, les
   capacités du pilote actif (`revocation`, `revokesImmediately`, `decisionPoint`, `encodes`,
   `passageReporting`) — cloisonné comme `SupervisionProvider`.

Câblage DI (`app/config/services.yaml:57`) : le décorateur devient la cible de l'alias `PiloteAcces` ;
l'adaptateur réel (Simulateur par défaut) devient l'argument `$pilote` du décorateur — **une seule ligne
à changer** pour activer un jour Itbox/SmartAccess, comme aujourd'hui.

**Écart signalé par rapport au §6 de la spec** : la table de la spec propose `AccesCapacites.id = id du
Controleur ou 'live' si vue globale` (un objet singleton, patron strict `Supervision`). Ce plan retient
à la place une **collection** (`GetCollection /acces/capabilities`, un item par `Controleur`, +
`Get /acces/capabilities/{id}` pour un contrôleur précis) car c'est la lecture la plus fidèle du texte
littéral de RG-ACC1-07 (« **pour chaque contrôleur** visible... la ressource expose ») et de CA-5 (« il
voit, **pour chaque contrôleur** de A... »). Le patron repris de `Supervision`/`SupervisionProvider` est
l'**idiome** (ApiResource non-Doctrine + Provider + cloisonnement `ContexteEtablissement`), pas la forme
exacte de la charge utile. À confirmer par l'intégrateur — bascule vers un singleton `'live'` est un
changement mineur si retenu (une ligne de moins dans le provider, la boucle `foreach Controleur`
disparaît), mais s'écarterait alors de la lettre de RG-ACC1-07.

## 1. Entités & schéma

**Aucune nouvelle entité Doctrine, aucun champ ajouté à une entité existante.**

| Objet | Nature | Notes |
|---|---|---|
| `App\Acces\Exception\UndeclaredCapabilityException` | classe PHP simple, `extends \RuntimeException` | pas d'entité |
| `App\Acces\Adapter\CapabilityGuardedPiloteAcces` | service, `implements PiloteAcces` | décore l'adaptateur réel, pas d'entité |
| `App\Audit\Entity\EntreeAudit` (**existant, réutilisé sans modification de schéma**) | entité Doctrine | `action = 'access.capability_gap_detected'` (nouvelle valeur de la colonne `action VARCHAR(120)`, déjà assez large — même remarque que pour `caisse.alerte_ecart`), `cibleType = 'Controleur'`, `cibleId` = id du contrôleur ou `null`, `etablissement` = dérivé du contrôleur |
| `App\Acces\ApiResource\AccessCapabilities` (**nouvelle, non-Doctrine**) | DTO API Platform | pas de table, pas de mapping ORM |

> id = UUID (`symfony/uid`) partout où une entité est concernée (`EntreeAudit`, `Controleur`) — inchangé.
> Rattachement multi-entités : `AccessCapabilities` est cloisonnée par `Etablissement` via
> `ContexteEtablissement::etablissementActif()` (comme `Supervision`), jamais un id fourni par le client.

## 2. Le décorateur `CapabilityGuardedPiloteAcces`

Fichier : `app/src/Acces/Adapter/CapabilityGuardedPiloteAcces.php`.

### 2.1 Mapping opération → capacité (RG-ACC1-01)

| Opération | Garde | Condition évaluée sur `capabilities()` du pilote **décoré** | Capacité manquante consignée si échec |
|---|---|---|---|
| `ouvrir()` | **aucune** | — délégation directe, toujours | — (pas d'`UndeclaredCapabilityException` possible ici, CA-9) |
| `recevoirEvenement()` | oui | `reportsState()` (`passageReporting !== PassageReporting::None`) | `PassageReporting::None->value` |
| `heartbeat()` | oui | `reportsState()` | `PassageReporting::None->value` |
| `pousserListeRevocation()` | oui | `acceptsRevocationList()` (`revocation !== RevocationCapability::Impossible`) | `RevocationCapability::Impossible->value` |

`capabilities()` elle-même délègue sans garde (elle ne peut pas échouer par construction — c'est la
source de vérité).

### 2.2 Squelette

```php
<?php

declare(strict_types=1);

namespace App\Acces\Adapter;

use App\Acces\Dto\EtatControleurDto;
use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Dto\OuvertureContexte;
use App\Acces\Dto\ResultatCommande;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\ListeRevocation;
use App\Acces\Exception\UndeclaredCapabilityException;
use App\Acces\Port\AccessDriverCapabilities;
use App\Acces\Port\PiloteAcces;
use App\Audit\Service\JournalAudit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Garde d'échec explicite (D17/ACC-1, RG-ACC1-01..05) : enveloppe l'adaptateur réel (composition) et
 * vérifie, avant de déléguer, que `capabilities()` déclare bien la capacité requise par l'opération
 * appelée. Une capacité manquante lève `UndeclaredCapabilityException` — jamais un no-op, jamais un
 * `ResultatCommande::echec()` masqué. C'est LUI qui est câblé sur l'alias DI `App\Acces\Port\PiloteAcces`
 * (`config/services.yaml`) : l'adaptateur réel devient un service interne, injecté ici.
 *
 * `ouvrir()` n'a pas de garde : aucun axe de `AccessDriverCapabilities` n'exprime « ce pilote ne sait pas
 * ouvrir » (RG-ACC1-01, ⚠ hypothèse actée) — un pilote qui existe sait par construction commander une
 * ouverture. L'échec « protocole non cadré » d'`ItboxAdapter`/`SmartAccessAdapter` sur `ouvrir()` reste
 * donc inchangé (CA-9), il n'est ni remplacé ni doublé par ce décorateur.
 */
final class CapabilityGuardedPiloteAcces implements PiloteAcces
{
    public function __construct(
        private readonly PiloteAcces $pilote,
        private readonly JournalAudit $journal,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function capabilities(): AccessDriverCapabilities
    {
        return $this->pilote->capabilities();
    }

    public function ouvrir(Equipement $equipement, OuvertureContexte $contexte): ResultatCommande
    {
        // RG-ACC1-01 : pas de garde de capacité dédiée sur cette opération (cf. docblock de classe).
        return $this->pilote->ouvrir($equipement, $contexte);
    }

    public function recevoirEvenement(EvenementPassageDto $evenement): void
    {
        $this->garantirCapacite(
            'recevoirEvenement',
            $this->pilote->capabilities()->reportsState(),
            \App\Acces\Enum\PassageReporting::None->value,
            null,
        );
        $this->pilote->recevoirEvenement($evenement);
    }

    public function heartbeat(Controleur $controleur): EtatControleurDto
    {
        $this->garantirCapacite(
            'heartbeat',
            $this->pilote->capabilities()->reportsState(),
            \App\Acces\Enum\PassageReporting::None->value,
            $controleur,
        );

        return $this->pilote->heartbeat($controleur);
    }

    public function pousserListeRevocation(Controleur $controleur, ListeRevocation $liste): ResultatCommande
    {
        $this->garantirCapacite(
            'pousserListeRevocation',
            $this->pilote->capabilities()->acceptsRevocationList(),
            \App\Acces\Enum\RevocationCapability::Impossible->value,
            $controleur,
        );

        return $this->pilote->pousserListeRevocation($controleur, $liste);
    }

    /**
     * RG-ACC1-02/04 : trace l'audit AVANT de lever (§3), jamais un no-op. `$capable` est déjà le
     * résultat évalué de l'axe requis par l'opération (RG-ACC1-01) — chaque opération est jugée
     * indépendamment des 3 autres (cas limite spec §8, pilote à capacités partielles).
     */
    private function garantirCapacite(string $operation, bool $capable, string $missingCapability, ?Controleur $controleur): void
    {
        if ($capable) {
            return;
        }

        $this->journal->enregistrer(
            'access.capability_gap_detected',
            'Controleur',
            $controleur !== null ? (string) $controleur->getId() : null,
            $controleur?->getEtablissement()?->getId(),
        );
        // Flush immédiat : survit à l'échec HTTP qui suit (§3 « survie au rollback »).
        $this->em->flush();

        throw new UndeclaredCapabilityException(
            $operation,
            $this->pilote::class,
            $missingCapability,
            $controleur?->getId(),
        );
    }
}
```

Note d'implémentation : les `use` d'enum sont écrits en FQCN inline ci-dessus pour lisibilité du plan ;
en implémentation réelle, les remonter en imports `use App\Acces\Enum\PassageReporting;` /
`use App\Acces\Enum\RevocationCapability;` en tête de fichier (style du reste du module).

### 3. Traçabilité — mécanisme de survie au rollback (RG-ACC1-04)

**Constat vérifié sur les 3 call sites actuels** (`app/src/Acces/Service/{ValidationPassageHandler,
OuvertureManuelleHandler,EtatReseauHandler}.php`) : aucun n'invoque une opération **gardée**
(`heartbeat`/`recevoirEvenement`/`pousserListeRevocation`) depuis l'intérieur d'une transaction Doctrine
explicitement ouverte sur la même connexion.
- `ValidationPassageHandler::valider()` appelle `$this->pilote->ouvrir(...)` (non gardée) **après** le
  retour de `$this->connection->transactional(...)` (ligne 269, hors closure).
- `OuvertureManuelleHandler::ouvrir()` appelle `$this->pilote->ouvrir(...)` (non gardée) **après**
  `$this->em->flush()` (ligne 56, aucune transaction explicite ouverte).
- `EtatReseauHandler::pulser()` appelle `$this->pilote->heartbeat($controleur)` (gardée) en **tout
  premier** (ligne 28), avant toute modification/`flush()` de la méthode — rien n'est en attente dans
  l'`EntityManager` à cet instant.
- `recevoirEvenement()`/`pousserListeRevocation()` n'ont **aucun** call site applicatif aujourd'hui
  (spec §3 pt.4, vérifié par `grep`).

Doctrine ne wrap **pas** l'ensemble d'une requête HTTP dans une transaction ambiante par défaut (aucun
listener global de ce type dans ce dépôt, vérifié — les seules transactions explicites sont locales à
des handlers précis, ex. `ValidationPassageHandler`/`EmettreFactureDirecteHandler`). Un
`$em->flush()` appelé alors qu'aucune transaction n'est ouverte **committe immédiatement** (niveau
d'imbrication Doctrine 0→1→0).

**Mécanisme retenu (simple, conforme au patron déjà établi par `EcouteurConnexion` —
`app/src/Securite/Security/EcouteurConnexion.php:47-51/68-69` : `enregistrer()` puis `flush()`
immédiat, pas différé à la fin de la méthode appelante)** : le décorateur appelle
`$this->journal->enregistrer(...)` puis `$this->em->flush()` **avant** de lever
`UndeclaredCapabilityException`. Étant donné le constat ci-dessus, ce flush committe réellement l'entrée
en base avant que l'exception ne remonte à travers Symfony — elle est donc consultable via
`GET /audit/entrees` **même si** la requête HTTP se termine ensuite en 4xx/5xx (CA-2 tel qu'écrit).

**Limite documentée, non traitée par ce lot (risque résiduel, §9)** : si un **futur** call site invoque
une opération gardée depuis l'intérieur d'une transaction explicite déjà ouverte sur la **même**
connexion (`Connection::beginTransaction()`/`transactional()`), le `flush()` du décorateur n'y committera
rien (Doctrine ne committe qu'au niveau d'imbrication 0) — un rollback ultérieur de cette transaction
ambiante emporterait l'entrée d'audit avec lui. La seule garantie inconditionnelle (indépendante de tout
appelant présent ou futur) serait une **connexion DBAL secondaire dédiée à l'audit** (pattern
« transaction autonome ») ; jugée disproportionnée aujourd'hui (aucun call site ne le justifie, aucune
infrastructure de ce type n'existe ailleurs dans le dépôt) — **non retenue**, au profit du mécanisme
simple ci-dessus. **Règle de contrat à documenter dans le docblock du décorateur et à surveiller en
revue** : ne jamais appeler une opération gardée de `PiloteAcces` depuis l'intérieur d'un
`$connection->transactional()`/`beginTransaction()` sans réévaluer ce mécanisme.

## 4. `UndeclaredCapabilityException`

Fichier : `app/src/Acces/Exception/UndeclaredCapabilityException.php` (nouveau namespace
`App\Acces\Exception`).

```php
<?php

declare(strict_types=1);

namespace App\Acces\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * RG-ACC1-02 : levée par `App\Acces\Adapter\CapabilityGuardedPiloteAcces` quand une opération de
 * `PiloteAcces` est appelée alors que `capabilities()` de l'adaptateur réel ne la couvre pas. Distincte
 * de l'exception « protocole non cadré » d'`ItboxAdapter`/`SmartAccessAdapter` (CA-9) : les deux causes
 * peuvent coexister chez un même adaptateur.
 */
final class UndeclaredCapabilityException extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $adapterClass,
        public readonly string $missingCapability,
        public readonly ?Uuid $controleurId = null,
    ) {
        parent::__construct(sprintf(
            'Capacité non déclarée : %s ne sait pas exécuter "%s" (%s manquant)%s.',
            $adapterClass,
            $operation,
            $missingCapability,
            $controleurId !== null ? sprintf(' [contrôleur %s]', $controleurId) : '',
        ));
    }
}
```

Aucun mapping HTTP dédié n'est requis pour ce lot (contrairement à `UnprocessableEntityHttpException`
utilisée ailleurs) : cette exception naît dans un décorateur de **port**, pas dans un `Processor`
API Platform exposé directement — elle remonte telle quelle jusqu'à l'appelant (le handler applicatif),
qui aujourd'hui ne la catch pas plus qu'il ne catchait déjà l'exception « protocole non cadré » d'Itbox
(RG-ACC1-05, non-régression : comportement de propagation inchangé). Si un futur lot souhaite un rendu
HTTP qualifié (au lieu d'un 500 brut), un `ExceptionListener` dédié est hors périmètre ACC-1 — à
proposer séparément si le besoin apparaît (aucun CA de ce lot ne l'exige).

## 5. Câblage DI (`app/config/services.yaml`)

Remplacer la ligne 57 actuelle :

```yaml
App\Acces\Port\PiloteAcces: '@App\Acces\Adapter\SimulateurAccesAdapter'
```

par :

```yaml
    # --- L3 Contrôle d'accès : ports & adaptateurs (§2 du plan-acces-terminal.md ; §2 plan-acc1.md) ---
    # Pilote matériel réel enfichable (OSDP/API ITBOX/SmartAccess non cadrés, point ouvert n°1) :
    # simulateur logiciel en dev/test, ItboxAdapter/SmartAccessAdapter restent des squelettes (à cadrer
    # IT Cotation). Service INTERNE désormais (ACC-1, RG-ACC1-03/D-1) : injecté DANS le décorateur de
    # garde de capacités ci-dessous, plus jamais résolu directement par un handler applicatif. Seule
    # ligne à changer pour activer un adaptateur réel une fois son protocole cadré (E-4/D19).
    App\Acces\Adapter\CapabilityGuardedPiloteAcces:
        arguments:
            $pilote: '@App\Acces\Adapter\SimulateurAccesAdapter'
    # Alias public du port : c'est le décorateur qui est câblé, pas l'adaptateur réel (ACC-1, D-1) — il
    # vérifie le mapping opération→capacité (RG-ACC1-01) avant de déléguer. Les 3 call sites existants
    # (ValidationPassageHandler, OuvertureManuelleHandler, EtatReseauHandler) sont INCHANGÉS : ils
    # injectent déjà l'interface PiloteAcces, jamais une classe d'adaptateur concrète (RG-ACC1-05,
    # vérifié §10 de la spec — sinon la garde serait contournée silencieusement).
    App\Acces\Port\PiloteAcces: '@App\Acces\Adapter\CapabilityGuardedPiloteAcces'
```

`App\Acces\Adapter\SimulateurAccesAdapter`, `ItboxAdapter`, `SmartAccessAdapter` et
`CapabilityGuardedPiloteAcces` restent tous auto-enregistrés comme services par `App\: resource: '../src/'`
(`_defaults: autowire: true`) — aucune définition de service supplémentaire n'est requise, seuls les
deux blocs ci-dessus (override explicite) sont nécessaires. `JournalAudit` et `EntityManagerInterface`
s'injectent par autowiring standard dans le décorateur, sans configuration additionnelle.

**Vérification de non-contournement (RG-ACC1-05/CA-4, risque §10 de la spec)** : confirmé par lecture des
3 handlers — `ValidationPassageHandler`, `OuvertureManuelleHandler`, `EtatReseauHandler` déclarent tous
`private readonly PiloteAcces $pilote` dans leur constructeur (interface, jamais une classe concrète).
Un test dédié (§8, `PiloteAccesTest` étendu) verrouille ce point.

## 6. API Platform — ressource `AccessCapabilities`

### 6.1 Ressource

Fichier : `app/src/Acces/ApiResource/AccessCapabilities.php`.

```php
<?php

declare(strict_types=1);

namespace App\Acces\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Acces\State\AccessCapabilitiesProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Restitution des capacités du pilote de contrôle d'accès actif (D17, RG-ACC1-06..09) : ce que le site
 * sait réellement faire — révocation immédiate/différée/impossible, décision, encodage, remontée des
 * passages. Codes d'enum stables uniquement (RG-ACC1-08), aucun libellé français en dur — résolution via
 * clés i18n `acces.capability.*` côté présentation (App\I18n, pas encore implémenté, cf. plan §7).
 *
 * N'est PAS une entité Doctrine. `id` = id du `Controleur` : un item par contrôleur visible par
 * l'établissement actif (RG-ACC1-07), même périmètre que `Controleur::GetCollection`/`Supervision`.
 *
 * ⚠ Limite architecturale actuelle (D-3, spec §9) : `PiloteAcces` est un alias DI UNIQUE pour toute la
 * plateforme (config/services.yaml) — les valeurs renvoyées sont donc identiques pour tous les
 * contrôleurs d'un même établissement aujourd'hui, bien que la ressource soit déjà adressable par id de
 * `Controleur` (prête pour une granularité par contrôleur le jour où D17 l'exigera, sans changement de
 * contrat API).
 */
#[ApiResource(
    shortName: 'AccessCapabilities',
    operations: [
        new GetCollection(
            uriTemplate: '/acces/capabilities',
            security: "is_granted('PERM', 'acces.superviser')",
            provider: AccessCapabilitiesProvider::class,
            normalizationContext: ['groups' => ['access_capabilities:read']],
        ),
        new Get(
            uriTemplate: '/acces/capabilities/{id}',
            security: "is_granted('PERM', 'acces.superviser')",
            provider: AccessCapabilitiesProvider::class,
            normalizationContext: ['groups' => ['access_capabilities:read']],
        ),
    ],
)]
final class AccessCapabilities
{
    /** Id du `Controleur` restitué. */
    #[ApiProperty(identifier: true)]
    #[Groups(['access_capabilities:read'])]
    public string $id;

    /** `immediate` | `deferred` | `impossible` — clé i18n `acces.capability.revocation.<valeur>`. */
    #[Groups(['access_capabilities:read'])]
    public string $revocation;

    /** Dérivé de `revocation`, pour affichage direct sans recalcul front. */
    #[Groups(['access_capabilities:read'])]
    public bool $revokesImmediately;

    /** `server` | `controller` | `credential` — clé i18n `acces.capability.decision_point.<valeur>`. */
    #[Groups(['access_capabilities:read'])]
    public string $decisionPoint;

    /** Le pilote sait-il écrire une autorisation sur un médium (ACC-2, pas encore implémenté) ? */
    #[Groups(['access_capabilities:read'])]
    public bool $encodes;

    /** `real_time` | `on_sync` | `none` — clé i18n `acces.capability.passage_reporting.<valeur>`. */
    #[Groups(['access_capabilities:read'])]
    public string $passageReporting;
}
```

### 6.2 Provider

Fichier : `app/src/Acces/State/AccessCapabilitiesProvider.php`.

```php
<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\ApiResource\AccessCapabilities;
use App\Acces\Entity\Controleur;
use App\Acces\Port\AccessDriverCapabilities;
use App\Acces\Port\PiloteAcces;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * RG-ACC1-06/07 : restitue, pour chaque `Controleur` visible par l'établissement actif (même périmètre
 * que `SupervisionProvider`/`Controleur::GetCollection`), les capacités du pilote actif de la plateforme
 * (`PiloteAcces::capabilities()` — jamais gardée, ne peut pas lever). Cloisonné par
 * `ContexteEtablissement::etablissementActif()`, jamais un identifiant fourni par le client (RG-SOCLE-05).
 *
 * @implements ProviderInterface<AccessCapabilities>
 */
final class AccessCapabilitiesProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly PiloteAcces $pilote,
    ) {
    }

    /** @return AccessCapabilities|list<AccessCapabilities> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AccessCapabilities|array
    {
        $etablissement = $this->contexte->etablissementActif();

        if (isset($uriVariables['id'])) {
            $id = (string) $uriVariables['id'];
            $controleur = Uuid::isValid($id) ? $this->em->getRepository(Controleur::class)->find($id) : null;

            if (!$controleur instanceof Controleur
                || ($etablissement !== null && $controleur->getEtablissement()?->getId()?->equals($etablissement->getId()) !== true)
            ) {
                // 404 propre (pas de fuite d'existence cross-établissement), même patron que
                // RevoquerTerminalProcessor/PerimetreAccesExtension.
                throw new NotFoundHttpException('Contrôleur introuvable.');
            }

            return $this->construire((string) $controleur->getId());
        }

        $criteres = $etablissement !== null ? ['etablissement' => $etablissement] : [];
        /** @var list<Controleur> $controleurs */
        $controleurs = $this->em->getRepository(Controleur::class)->findBy($criteres);

        return array_map(fn (Controleur $c): AccessCapabilities => $this->construire((string) $c->getId()), $controleurs);
    }

    private function construire(string $controleurId): AccessCapabilities
    {
        $cap = $this->pilote->capabilities();

        $vue = new AccessCapabilities();
        $vue->id = $controleurId;
        $vue->revocation = $cap->revocation->value;
        $vue->revokesImmediately = $cap->revokesImmediately();
        $vue->decisionPoint = $cap->decisionPoint->value;
        $vue->encodes = $cap->encodes();
        $vue->passageReporting = $cap->passageReporting->value;

        return $vue;
    }
}
```

`RG-ACC1-09` (échec fermé) est satisfaite **par construction**, sans code spécifique : quand le pilote
actif est `ItboxAdapter`/`SmartAccessAdapter`, `capabilities()` renvoie
`AccessDriverCapabilities::unspecified()` (`revocation = Impossible`, `passageReporting = None`,
`encoding = None`) — `construire()` lit ces valeurs directement, jamais de `null`/valeur par défaut
optimiste (CA-8).

## 7. i18n — clés `acces.capability.*` (RG-ACC1-08)

**Constat vérifié** : aucune infrastructure i18n n'existe encore dans ce dépôt (`grep` sur
`app/translations/` : aucun résultat ; `App\I18n` explicitement *« à implémenter »* au statut du contrat,
`COORDINATION/CONTRACT/i18n-traduction.md`). Créer un catalogue committé isolé pour ce seul module
préempterait une décision d'infrastructure transverse qui n'existe pas encore ailleurs dans l'app — non
retenu (sur-construction ponctuelle, incohérente avec le reste du code).

**Ce que ce lot fait à la place, conformément à RG-ACC1-08** : le back n'expose que des **codes d'enum
stables** (déjà le cas, §6.1/6.2 ci-dessus — jamais de chaîne française en dur dans la réponse API,
CA-7) ; les clés i18n candidates sont **déclarées en commentaire** dans le docblock de
`AccessCapabilities` et listées ici, prêtes à être reprises telles quelles le jour où `App\I18n` +
l'agent de traduction seront construits (portée transverse au noyau, hors ACC-1) :

| Clé | Source `en` (à traduire) |
|---|---|
| `acces.capability.revocation.immediate` | Immediate revocation |
| `acces.capability.revocation.deferred` | Deferred revocation (next sync) |
| `acces.capability.revocation.impossible` | Revocation not possible |
| `acces.capability.decision_point.server` | Server decides |
| `acces.capability.decision_point.controller` | Controller decides (works offline) |
| `acces.capability.decision_point.credential` | Credential decides (autonomous lock) |
| `acces.capability.passage_reporting.real_time` | Real-time reporting |
| `acces.capability.passage_reporting.on_sync` | Reporting on sync only |
| `acces.capability.passage_reporting.none` | No reporting |

`encodes` reste un booléen brut (pas d'axe à énumérer, RG-ACC1-07 ne demande pas de clé dédiée) — un
« Oui »/« Non » générique suffira côté présentation, hors périmètre de ce lot.

## 8. Sécurité & droits

- **Permission de lecture** : `acces.superviser` (D-5, réutilisée — déjà portée par `GET
  /acces/supervision`, US-L3-06) sur les deux opérations `GetCollection`/`Get` de `AccessCapabilities`.
  Aucune nouvelle permission créée. Si un rôle sans supervision temps réel doit un jour consulter cette
  restitution sans le reste de l'écran A-03, `acces.lire` (déjà utilisé par `Controleur`) serait
  l'alternative — non tranché ici, différé (spec D-5, non bloquant : aucun rôle métier connu aujourd'hui
  ne le requiert).
- **Aucune nouvelle permission d'écriture.** Le décorateur ne s'authentifie pas différemment des call
  sites qu'il enveloppe — la sécurité applicative des opérations `PiloteAcces` (qui a le droit d'ouvrir,
  de pulser un contrôleur…) reste portée par les `security:` des ressources API amont
  (`Passage`, `Controleur`), inchangées.
- **Cloisonnement (RG-ACC1-06)** :
  - Restitution : filtrée par `ContexteEtablissement::etablissementActif()` exactement comme
    `SupervisionProvider` — jamais un id fourni par le client. Un exploitant de B interrogeant
    `/acces/capabilities/{id du contrôleur de A}` reçoit 404 (`AccessCapabilitiesProvider::provide()`,
    §6.2).
  - Audit : `etablissement` de l'`EntreeAudit` dérivé de `$controleur->getEtablissement()` — **jamais**
    du contexte HTTP (§3, D3/D8). Quand l'opération ne porte pas de `Controleur`
    (`recevoirEvenement()` aujourd'hui non liée à un contrôleur, cf. signature du port), `etablissement`
    reste `null` — cas déjà géré par `JournalAudit::enregistrer()` (paramètre `?Uuid`).
- **Voter** : aucun nouveau voter. `is_granted('PERM', ...)` réutilise le voter `PERM` existant,
  identique à `Supervision`/`Controleur`.

## 9. Migrations

**Aucune migration requise.**
- `CapabilityGuardedPiloteAcces` et `UndeclaredCapabilityException` sont des classes PHP simples, pas des
  entités.
- `AccessCapabilities` est une ressource API Platform **non-Doctrine** (comme `Supervision`,
  `SynchronisationAcces`) — pas de table.
- `EntreeAudit` (`audit_entree`) est réutilisée sans modification de schéma : `action VARCHAR(120)`,
  `cibleType VARCHAR(180)`, `cibleId VARCHAR(64) NULL`, `etablissement UUID NULL` acceptent déjà la
  nouvelle valeur `access.capability_gap_detected` et `cibleType = 'Controleur'` sans changement de
  colonne (même remarque déjà faite pour `caisse.alerte_ecart`, ACC-3 §4 pour `TypeDroitAcces::Booking`
  sur une colonne assez large).

## 10. Tests

Fichiers nouveaux, sauf mention « existant, à étendre ».

| Test | Type | Couvre |
|---|---|---|
| `App\Tests\Acces\Fixtures\MixedCapabilityTestAdapter` (nouveau, `app/tests/Acces/Fixtures/MixedCapabilityTestAdapter.php`) | Double de test, `implements PiloteAcces` | `capabilities()` renvoie `revocation = Deferred`, `passageReporting = None`, `decisionPoint = Controller`, `encoding = None` — les 3 opérations déléguées ne font rien d'observable (retours neutres `ResultatCommande::ok()`/`EtatControleurDto` factice) ; sert uniquement à isoler les 4 axes indépendamment (cas limite spec §8) |
| `CapabilityGuardedPiloteAccesTest::testHeartbeatSurPiloteUnspecifiedLeveEtTraceAudit` | Unitaire (`KernelTestCase`, patron `PiloteAccesTest`) | **CA-1/CA-2/CA-3** : décorateur construit avec `ItboxAdapter` interne, `heartbeat()` lève `UndeclaredCapabilityException` (champs `operation='heartbeat'`, `adapterClass=ItboxAdapter::class`, `missingCapability='none'`, `controleurId` renseigné) ; `$em->clear()` puis relecture `EntreeAudit` par `cibleId` confirme la persistance réelle en base (action, cibleType, etablissement dérivé du contrôleur) |
| `CapabilityGuardedPiloteAccesTest::testPousserListeRevocationSurPiloteUnspecifiedLeveException` | Unitaire | **CA-1** : idem sur `pousserListeRevocation()`, `missingCapability = 'impossible'` |
| `CapabilityGuardedPiloteAccesTest::testRecevoirEvenementSurPiloteUnspecifiedLeveExceptionSansCible` | Unitaire | Garde sur `recevoirEvenement()` ; `EntreeAudit.cibleId = null` (opération non liée à un `Controleur`) |
| `CapabilityGuardedPiloteAccesTest::testOuvrirDelegueDirectementSansGardeDeCapacite` | Unitaire | **CA-9, RG-ACC1-01** : décorateur construit avec `ItboxAdapter` interne, `ouvrir()` lève l'exception **existante** « protocole non cadré » (`\RuntimeException`, message ITBOX), **jamais** `UndeclaredCapabilityException` — distinction des deux causes |
| `CapabilityGuardedPiloteAccesTest::testCapacitesMixtesEvalueChaqueOperationIndependamment` | Unitaire | Cas limite spec §8 : décorateur construit avec `MixedCapabilityTestAdapter` — `heartbeat()` lève (`passageReporting = None`), `pousserListeRevocation()` **réussit** (`revocation = Deferred ≠ Impossible`) ; prouve qu'un pilote « globalement capable » n'est pas présumé capable sur les 4 axes |
| `CapabilityGuardedPiloteAccesTest::testSimulateurNonRegressionOuvrirHeartbeatPousserListeRevocation` | Unitaire | **CA-4** : décorateur construit avec `SimulateurAccesAdapter` interne — `ouvrir()`/`heartbeat()`/`pousserListeRevocation()` renvoient exactement les mêmes résultats qu'un appel direct au simulateur (comparaison avec `SimulateurAccesAdapter` nu, patron `PiloteAccesTest::testSimulateurOuvreEtHeartbeatSansAucunProtocoleMateriel`) ; aucune `EntreeAudit` créée |
| `App\Tests\Acces\Unit\PiloteAccesTest::testAliasResoutVersLeDecorateurEnTest` (existant, à modifier) | Unitaire | **RG-ACC1-03/non-régression câblage** : `static::getContainer()->get(PiloteAcces::class)` renvoie désormais une instance de `CapabilityGuardedPiloteAcces` (et non plus directement `SimulateurAccesAdapter`) ; test complémentaire vérifiant que les 3 handlers (`ValidationPassageHandler`, `OuvertureManuelleHandler`, `EtatReseauHandler`) déclarent tous `PiloteAcces` (interface) dans leur constructeur, jamais une classe d'adaptateur concrète (garde contre contournement silencieux, risque §10 spec) |
| `App\Tests\Acces\Api\AccessCapabilitiesTest::testCa5RestitutionExposeCapacitesParControleurPourEtablissementActif` (nouveau, `app/tests/Acces/Api/AccessCapabilitiesTest.php`, patron `AccesApiTestCase`) | Fonctionnel API | **CA-5** : `GET /api/acces/capabilities` avec le Simulateur actif (défaut) — au moins un item, `revocation='immediate'`, `revokesImmediately=true`, `decisionPoint='server'`, `encodes=false`, `passageReporting='real_time'`, `id` = id du `Controleur` de fixtures |
| `AccessCapabilitiesTest::testGetParIdControleurRenvoieLesMemesCapacites` | Fonctionnel API | **D-3** : `GET /api/acces/capabilities/{idControleur}` renvoie le même contenu que l'item correspondant de la collection |
| `AccessCapabilitiesTest::testCa6CloisonnementEtablissementBNeVoitAucunControleurDeA` | Fonctionnel API | **CA-6** : exploitant scopé B, `GET /api/acces/capabilities` (collection) — aucun id de `Controleur` de A dans la réponse ; `GET /api/acces/capabilities/{idControleurDeA}` avec en-tête B → 404 (patron `CloisonnementTest`) |
| `AccessCapabilitiesTest::testCa7AucunLibelleFrancaisEnDurSeulsDesCodesEnumStables` | Fonctionnel API | **CA-7** : assertion stricte que les valeurs `revocation`/`decisionPoint`/`passageReporting` sont exactement dans `{'immediate','deferred','impossible'}`/`{'server','controller','credential'}`/`{'real_time','on_sync','none'}` — jamais de texte libre |
| `AccessCapabilitiesTest::testCa8PiloteUnspecifieAfficheImpossibleNoneFalse` | Fonctionnel API | **CA-8** : override du service `App\Acces\Adapter\CapabilityGuardedPiloteAcces` dans le conteneur de test (`self::getContainer()->set(...)`, `framework.test: true` déjà actif en `when@test`, `app/config/packages/framework.yaml:13`) par une instance construite avec `ItboxAdapter` interne, **avant** l'appel HTTP — `GET /api/acces/capabilities` renvoie `revocation='impossible'`, `passageReporting='none'`, `encodes=false` pour chaque contrôleur, jamais un champ absent/`null` |
| `App\Tests\Acces\Unit\PermissionVoterNonRegressionTest` / `App\Tests\Acces\Api\AccesPassagesSynchroNonRegressionTest` (existants, non modifiés) | Non-régression | Suite complète du module `App\Acces` rejouée sans modification attendue — confirme qu'aucun comportement existant n'a bougé (RG-ACC1-05) |

**Détail `MixedCapabilityTestAdapter`** (namespace `App\Tests\Acces\Fixtures`, D-4 anglais — c'est un
double de test, pas un identifiant métier, mais suit la même discipline que le reste du module) :

```php
<?php

declare(strict_types=1);

namespace App\Tests\Acces\Fixtures;

use App\Acces\Dto\EtatControleurDto;
use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Dto\OuvertureContexte;
use App\Acces\Dto\ResultatCommande;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\ListeRevocation;
use App\Acces\Enum\CredentialEncoding;
use App\Acces\Enum\DecisionPoint;
use App\Acces\Enum\EtatControleur;
use App\Acces\Enum\PassageReporting;
use App\Acces\Enum\RevocationCapability;
use App\Acces\Port\AccessDriverCapabilities;
use App\Acces\Port\PiloteAcces;

/**
 * Double de test (cas limite spec §8) : capacités PARTIELLES délibérément incohérentes — accepte une
 * liste de révocation différée mais ne remonte jamais rien. Prouve que le décorateur évalue chaque
 * opération sur son propre axe, sans présumer qu'un pilote « capable sur un axe » l'est sur les 4.
 */
final class MixedCapabilityTestAdapter implements PiloteAcces
{
    public function capabilities(): AccessDriverCapabilities
    {
        return new AccessDriverCapabilities(
            DecisionPoint::Controller,
            RevocationCapability::Deferred,
            CredentialEncoding::None,
            PassageReporting::None,
        );
    }

    public function ouvrir(Equipement $equipement, OuvertureContexte $contexte): ResultatCommande
    {
        return ResultatCommande::ok('Test double.');
    }

    public function recevoirEvenement(EvenementPassageDto $evenement): void
    {
    }

    public function heartbeat(Controleur $controleur): EtatControleurDto
    {
        return new EtatControleurDto(EtatControleur::EnLigne, new \DateTimeImmutable());
    }

    public function pousserListeRevocation(Controleur $controleur, ListeRevocation $liste): ResultatCommande
    {
        return ResultatCommande::ok('Test double.');
    }
}
```

## 11. Tâches (voir `tasks-acc1.md`)

1. **T1** — `App\Acces\Exception\UndeclaredCapabilityException` (§4).
2. **T2** — `App\Acces\Adapter\CapabilityGuardedPiloteAcces` (§2), y compris le mécanisme d'audit
   immédiat (§3).
3. **T3** — Câblage `app/config/services.yaml` (§5) : décorateur + alias `PiloteAcces`.
4. **T4** — `App\Acces\ApiResource\AccessCapabilities` + `App\Acces\State\AccessCapabilitiesProvider`
   (§6).
5. **T5** — Double de test `MixedCapabilityTestAdapter` (§10).
6. **T6** — Tests unitaires décorateur (`CapabilityGuardedPiloteAccesTest`, CA-1/2/3/4/8/9 + cas limite) +
   extension `PiloteAccesTest`.
7. **T7** — Tests API `AccessCapabilitiesTest` (CA-5/6/7/8).
8. **T8** — Déclaration des clés i18n en docblock (§7) — pas de fichier de catalogue créé.
9. **T9** — `composer cs-fix` / `phpstan` + `bin/phpunit` complet module `App\Acces` (non-régression
   `ValidationPassageTest`, `SupervisionTest`, `HorsLigneTest`, `PermissionVoterNonRegressionTest`,
   `AccesPassagesSynchroNonRegressionTest`…) + `GET /health`.

Ordre : T1 → T2 → T3 → T4 (peuvent être développés en parallèle T1/T4 puisque indépendants, mais T3
dépend de T2) → T5 → T6 → T7 → T8 → T9.

## 12. Risques / à valider

- **Risque connexe hors périmètre — `ResultatCommande::echec()` ignoré aux call sites `ouvrir()`**
  (spec §3 pts.1-2, §8) : `ValidationPassageHandler::valider()` (ligne 269) et
  `OuvertureManuelleHandler::ouvrir()` (ligne 58) ignorent totalement le `ResultatCommande` retourné par
  `pilote->ouvrir(...)`, alors que le `Passage` est déjà persisté `Valide` **avant** cet appel. Ce n'est
  **pas** un défaut de capacité non déclarée (l'opération `ouvrir()` n'a pas de garde, RG-ACC1-01) mais un
  silence sur échec fonctionnel du même ordre de gravité (« la plateforme croit avoir réussi »), sur un
  mécanisme distinct (retour de fonction ignoré). **Non traité par ce lot** — latent aujourd'hui (le
  Simulateur renvoie toujours `ok`), actif seulement le jour où un pilote réel renverrait
  `ResultatCommande::echec()` sans lever. **Proposé en lot séparé** (ex. « ACC-1bis : retour d'ouverture
  vérifié ») touchant `ValidationPassageHandler`/`OuvertureManuelleHandler`, hors ACC-1 au sens strict —
  à confirmer par l'intégrateur.
- **`pousserListeRevocation` protège une opération non atteignable en production aujourd'hui** (spec §3
  pt.4) : aucun call site n'invoque cette méthode (`BlocageSupportHandler::propagerRevocation()` reste en
  pull, jamais en push). Le test CA-1 sur cette opération reste un test de contrat du décorateur, pas un
  test d'intégration bout-en-bout — ne pas en conclure que la garde est superflue.
- **Survie de l'audit au rollback — limite documentée (§3)** : le mécanisme retenu (flush immédiat)
  suffit à tous les call sites actuels (vérifié) et satisfait CA-2 tel qu'écrit, mais ne serait plus
  garanti si un futur call site enveloppait un appel gardé dans une transaction Doctrine explicite déjà
  ouverte. Pas de connexion DBAL secondaire construite dans ce lot (jugé disproportionné) — règle de
  contrat à documenter dans le docblock du décorateur, à réévaluer si un tel call site apparaît.
- **D-2 (événement de domaine) non implémenté** dans ce lot, conforme à la décision de l'orchestrateur —
  catalogue d'événements non touché. À réévaluer si un abonné réel apparaît (ex. alerte support temps
  réel sur tentative bloquée).
- **D-3 (granularité pilote global)** : `AccessCapabilities` est prête à accepter un id de `Controleur`
  (opération `Get /acces/capabilities/{id}`) mais les valeurs restent identiques pour tous les
  contrôleurs d'un même établissement — limite documentée dans le docblock de la ressource. Le jour où un
  registre pilote-par-contrôleur existera, seul `AccessCapabilitiesProvider::construire()` change (résout
  le bon pilote via `Controleur.itboxRef` ou équivalent), pas le contrat API.
- **i18n — `App\I18n` non implémenté** : ce lot expose des codes d'enum stables et documente les clés
  candidates (§7) sans créer de fichier de catalogue isolé, pour ne pas préempter l'infrastructure
  transverse à construire séparément. À revisiter dès que `App\I18n`/l'agent de traduction existent.
- **Permission `acces.superviser` réutilisée (D-5)** : si un rôle métier sans supervision temps réel doit
  un jour consulter cette seule restitution, bascule vers `acces.lire` à trancher séparément — aucun
  besoin identifié aujourd'hui.
- **Écart de forme signalé (§0)** : ressource conçue comme collection (un item par `Controleur`) plutôt
  que le singleton `id='live'` esquissé au §6 de la spec — jugé plus fidèle au texte littéral de
  RG-ACC1-07/CA-5, à confirmer par l'intégrateur avant implémentation (bascule mineure si le singleton
  est préféré).
- **NF525/comptabilité publique** : sans objet — `EntreeAudit`, `AccessCapabilities` et
  `UndeclaredCapabilityException` ne sont pas des objets comptables, aucun point à faire valider par un
  expert compta ici.
