# Plan technique — Clôture de caisse (Z) à rôle gradué (`App\Caisse`, évolution M2)

- **Spec source :** specs/caisse-cloture-role/spec-caisse-cloture-role.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-CAISSEZ-01 à 04 · RG-CAISSEZ-01 à 09 · CA-1 à CA-11 (⚠ US/RG créées pour cette spec,
  absentes du `backlog.html`/`cahier-detaille.html` — cf. réserve en tête de `spec-caisse-cloture-role.md`,
  rappelée au §8 Risques). Étend US-L2-10/RG-M2-06 (`spec-vente.md`) sans les réécrire.

> **Réutilisation socle (aucune modification)** — `App\Securite\Entity\{Permission,Role,Affectation,
> Utilisateur}` ; `App\Securite\Security\PermissionVoter` (attribut `PERM`) ; `App\Securite\Service\
> {CalculateurDroits,ContexteEtablissement}` ; `Symfony\Bundle\SecurityBundle\Security::isGranted()`
> (déjà utilisé en programmatique dans plusieurs `Processor`, ex. `AjoutLigneProcessor::
> $this->security->isGranted('PERM', 'vente.forcer_prix')` — même patron réutilisé ici) ;
> `App\Audit\Service\JournalAudit` + `App\Audit\Entity\EntreeAudit` (append-only, RG-SOCLE-07).
>
> **Réutilisation M2 Vente & Caisse (code réel, modification additive minimale uniquement)** —
> `App\Caisse\State\CloturerSessionProcessor` (branchement de réponse + garde + création d'alerte,
> insertions ciblées, §4) ; `App\Caisse\Entity\ClotureZ` (**aucune modification de schéma**, seule sa
> `security:` d'exposition change, §2) ; `App\Caisse\Entity\PointDeVente` (**un seul champ additif**,
> `toleranceEcartCaisse`, même patron que `seuilAlerteRetrait`) ; `App\Caisse\Entity\SessionCaisse`
> (lecture seule, `getEtablissement()`/`getPointDeVente()`) ; `App\Vente\Doctrine\
> PerimetreVenteExtension` (ajout d'une entrée dans `CHEMINS`, §1) ; `App\Vente\Service\{LecteurCorps,
> PanierCalculateur}` (`centimes()`/`decimal()` réutilisés pour la comparaison écart/tolérance, §4) ;
> `App\Vente\Nf525\ScellementHandler` **non modifié** (le scellement NF525 de la `ClotureZ` reste
> strictement identique, RG-CAISSEZ-09).
>
> **Aucune extension du socle Audit générique** — `App\Audit\Doctrine\AuditWriteSubscriber::
> CLASSES_SURVEILLEES` **ne reçoit pas** `AlerteEcartCaisse` : son cycle de vie (création uniquement,
> jamais modifiée) est tracé par un appel explicite `JournalAudit::enregistrer('caisse.alerte_ecart', …)`
> à sémantique métier (RG-CAISSEZ-07, action nommée littéralement par la spec) — même choix et même
> justification que `DemandeEscalade` dans `plan-autorisation.md` §0 (éviter un doublon d'entrées pour le
> même événement).

---

## 0. Décisions structurantes (résumé)

1. **Point d'insertion unique = `CloturerSessionProcessor::process()`.** Aucun nouveau Processor, aucun
   nouveau Provider : le calcul (théorique, comptages, écart, `etatDeRegie`) reste **strictement
   inchangé** et continue d'être **toujours** persisté dans `ClotureZ` (RG-CAISSEZ-01). Seuls trois blocs
   sont insérés dans la même méthode : (a) une garde « comptage espèces requis » avant toute écriture,
   (b) la création conditionnelle de `AlerteEcartCaisse` juste avant le `flush()` final, (c) le choix du
   corps de réponse HTTP juste avant le `return`. Détail exact des points d'insertion au §4.
2. **La permission déterminant la réponse est évaluée par programmation (`Security::isGranted()`), pas
   par un deuxième `security:` d'opération.** L'opération `POST /sessions-caisse/{id}/cloturer` garde son
   unique garde `is_granted('PERM', 'caisse.cloturer')` (inchangée, API Platform). `CloturerSessionProcessor`
   reçoit `Symfony\Bundle\SecurityBundle\Security` en injection (même patron que `AjoutLigneProcessor`,
   `MouvementCaisseProcessor`) et appelle `$this->security->isGranted('PERM', 'caisse.voir_z')` **une
   seule fois**, résultat mémorisé dans une variable locale `$peutVoirZ`, réutilisé pour la garde (a) et le
   branchement de réponse (c).
3. **Comparaison écart/tolérance en centimes (entier), pas `bccomp`.** Le code de `CloturerSessionProcessor`
   manipule déjà tous les montants en centimes via `App\Vente\Service\PanierCalculateur::centimes()`
   avant de les reformater en `decimal(10,2)` (`decimal()`). La comparaison `|ecartTotal| >
   toleranceEcartCaisse` réutilise ces deux méthodes existantes (`abs($ecartTotalCentimes) >
   $this->calc->centimes($pdv->getToleranceEcartCaisse())`) plutôt que d'introduire `bccomp` (non utilisé
   ailleurs dans `App\Caisse`/`App\Vente`, où la convention est l'arithmétique entière en centimes) —
   aucune erreur d'arrondi flottant possible sur des montants déjà normalisés en centimes.
4. **Pas de champ `statut` sur `AlerteEcartCaisse`.** Le tableau §5 de `spec-caisse-cloture-role.md` (source
   de vérité de ce plan) ne liste **aucun** champ `statut`, et le §2 « Exclu » de la spec exclut
   explicitement « l'acquittement/la résolution d'une alerte » de cette version — l'alerte est « simplement
   listable ». Ce plan suit strictement le tableau §5 : `id, cloture, session, etablissement, ecartMontant,
   toleranceAppliquee, auteurCloture, horodatage`. (Point de vigilance : le brief de cadrage transmis à
   l'agent de planification mentionnait de façon informelle « établissement, PDV, clôture, montant écart,
   **statut**, auteur, horodatage » — reformulation approximative, non alignée avec le détail de la spec
   elle-même ; signalé au Risque n°1 pour confirmation explicite avec le client avant implémentation.)
5. **Pas de champ `pointDeVente` direct sur `AlerteEcartCaisse`.** Même lecture stricte du tableau §5 : la
   dénormalisation s'arrête à `session` (« dénormalisé de `cloture.session` pour lecture directe ») et
   `etablissement` (cloisonnement). Le point de vente reste accessible via `alerte.session.pointDeVente`
   pour un affichage back-office (une jointure suffit, volumétrie faible — une alerte par clôture en
   écart). Si le client veut filtrer/afficher le PDV sans jointure, un champ dénormalisé pourra être ajouté
   en évolution mineure additive.
6. **`AlerteEcartCaisse` n'est jamais créée si la session n'a pas de point de vente.** `SessionCaisse::
   getPointDeVente()` est typé nullable côté PHP (bien que `JoinColumn(nullable: false)` en pratique) ; sans
   PDV, aucune valeur de `toleranceEcartCaisse` n'est calculable — ce plan choisit de **ne pas** créer
   d'alerte dans ce cas plutôt que de supposer une tolérance implicite (défensif, cas qui ne devrait jamais
   se produire en usage réel). Signalé au Risque n°6.
7. **Une seule migration additive** pour tout le lot (table `AlerteEcartCaisse` + colonne
   `PointDeVente.toleranceEcartCaisse` + 2 permissions), même patron que `Version20260818120000`
   (module Autorisation) plutôt que plusieurs petites migrations séquentielles — le lot est cohérent et
   de taille modeste.
8. **Nouvelle base de test dédiée, pas de modification de `VenteApiTestCase`.** Pour tester des utilisateurs
   à droits différenciés (Caissier sans `caisse.voir_z`, Régisseur avec, Régisseur d'un second
   établissement sans affectation croisée — CA-7), ce plan introduit une fixture additive
   `App\Caisse\DataFixtures\CaisseClotureRoleFixtures` (dépend de `SocleFixtures`+`VenteFixtures`) et une
   base de test autonome `App\Tests\Caisse\CaisseClotureRoleApiTestCase` (même schéma de bootstrap que
   `VenteApiTestCase`/`DevisApiTestCase`, fixtures étendues) plutôt que de modifier `VenteApiTestCase`
   (partagé par d'autres suites M2 déjà vertes) — non-régression des tests existants garantie par
   construction (aucun fichier partagé modifié).

---

## 1. Entités & schéma

Namespace : `App\Caisse\Entity\*` (existant, augmenté). `declare(strict_types=1)` partout. Noms métier en
français. id = UUID (`symfony/uid`) pour toute nouvelle entité.

### 1.1 `AlerteEcartCaisse` (nouvelle, `App\Caisse\Entity\AlerteEcartCaisse`, table `caisse_alerte_ecart`)

| Champ | Type Doctrine | Null | Index/Contrainte | Relation | Notes |
|---|---|---|---|---|---|
| id | `uuid` (UuidType) | non | PK | — | RG-CAISSEZ-06 |
| cloture | `ManyToOne` → `ClotureZ` | non | **unique** (`UNIQUE INDEX`) | `JoinColumn(nullable: false, unique: true)` | « au plus une alerte par clôture », §7 cas limite spec |
| session | `ManyToOne` → `SessionCaisse` | non | index | `JoinColumn(nullable: false)` | dénormalisé de `cloture.session`, lecture directe |
| etablissement | `ManyToOne` → `App\Organisation\Entity\Etablissement` | non | index | `JoinColumn(nullable: false)` | cloisonnement RG-SOCLE-05, dénormalisé (comme `DemandeEscalade.etablissement`) |
| ecartMontant | `decimal(10,2)` | non | — | — | signé (positif = excédent, négatif = manque), = `ClotureZ.ecartTotal` figé à la création |
| toleranceAppliquee | `decimal(10,2)` | non | ≥ 0 (`Assert\PositiveOrZero`) | — | = `PointDeVente.toleranceEcartCaisse` figée à l'instant T (RG-CAISSEZ-06) |
| auteurCloture | `ManyToOne` → `App\Securite\Entity\Utilisateur` | non | index | `JoinColumn(nullable: false)` | qui a déclenché la clôture (peut être le caissier bas niveau) |
| horodatage | `datetime_immutable` | non | index | — | = horodatage de la `ClotureZ` associée (fixé explicitement par le processor, pas par le constructeur, §4) |

Pas de `statut`, pas de `pointDeVente` direct — cf. Décisions §0 n°4/5. Aucune opération d'écriture
exposée par l'API (immuable, cf. §2).

### 1.2 `PointDeVente` (existant, complété)

| Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|
| toleranceEcartCaisse | `decimal(10,2)`, `options: ['default' => '0.00']` | non | `Assert\PositiveOrZero` | RG-CAISSEZ-08, même patron exact que `seuilImpression` (colonne `NOT NULL DEFAULT '0.00'`, pas nullable contrairement à `seuilAlerteRetrait`) ; getter/setter `getToleranceEcartCaisse()`/`setToleranceEcartCaisse()`, `Groups(['pdv:read', 'pdv:write'])` |

### 1.3 `ClotureZ` (existant, **aucun changement de schéma**)

Champs et groupes de sérialisation `cloture:read`/`cloture:etat` **inchangés** (RG-CAISSEZ-01) — seule la
`security:` de ses opérations `Get`/`GetCollection`/`.../etat-regie` change (§2).

### 1.4 Cloisonnement multi-entités — `App\Vente\Doctrine\PerimetreVenteExtension`

Ajout d'une entrée dans la constante `CHEMINS` (aucune jointure intermédiaire nécessaire, `etablissement`
est un champ direct de `AlerteEcartCaisse`, contrairement à `MouvementCaisse`/`ClotureZ` qui passent par
`session`) :

```php
private const CHEMINS = [
    // … entrées existantes inchangées …
    AlerteEcartCaisse::class => '{root}.etablissement',
];
```

Aucune autre modification de cette classe (pas de nouveau cas dans `restreindre()`, le chemin direct suit
le même schéma que `PointDeVente`/`SessionCaisse`/`Vente`/`Avoir`).

---

## 2. API (API Platform)

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| **ClotureZ** *(existant)* | `GetCollection` | `is_granted('PERM', 'caisse.voir_z')` *(était `caisse.lire`, RG-CAISSEZ-03)* | `cloture:read` | inchangés |
| | `Get` | `is_granted('PERM', 'caisse.voir_z')` *(était `caisse.lire`)* | `cloture:read` | — |
| | `Get …/{id}/etat-regie` | `is_granted('PERM', 'caisse.voir_z')` *(était `caisse.lire`)* | `cloture:read`, `cloture:etat` | — |
| **SessionCaisse** *(existant, inchangé)* | `POST …/{id}/cloturer` | `is_granted('PERM', 'caisse.cloturer')` **inchangé** — la gradation de la réponse se joue **dans** `CloturerSessionProcessor`, pas dans `security:` (§0 n°2) | — | — |
| **PointDeVente** *(existant, inchangé)* | `Patch`/`Post` | `is_granted('PERM', 'caisse.gerer')` **inchangé** — porte désormais aussi `toleranceEcartCaisse` en écriture (`pdv:write`) | `pdv:read`/`pdv:write` (champ ajouté) | — |
| **AlerteEcartCaisse** *(nouveau)* | `GetCollection` | `is_granted('PERM', 'caisse.voir_ecart')` (RG-CAISSEZ-06 ; **indépendant** de `caisse.voir_z`, cf. §7 cas limite spec « supervision multi-site des écarts sans détail comptable ») | `alerte_ecart:read` | `ApiFilter(SearchFilter)` `etablissement` (exact), `session` (exact) |
| | `Get` | `is_granted('PERM', 'caisse.voir_ecart')` | `alerte_ecart:read` | — |
| | *(pas de `Post`/`Patch`/`Delete`)* | — | — | créée **uniquement** par `CloturerSessionProcessor` (RG-CAISSEZ-07 : « aucune alerte n'est jamais modifiable ou supprimable par l'API ») |

Cloisonnement `GetCollection`/`Get` de `AlerteEcartCaisse` assuré par `PerimetreVenteExtension` (§1.4),
identique au mécanisme déjà en place pour `PointDeVente`/`SessionCaisse`/`ClotureZ`.

---

## 3. Sécurité & droits

- **2 nouvelles permissions**, module `caisse` (RG-CAISSEZ-03/06) : `caisse.voir_z` (Z complet, détail
  par moyen, écart, état de régie), `caisse.voir_ecart` (consultation des alertes d'écart).
- **`caisse.cloturer` ne change pas de sémantique** — elle continue de garder l'opération d'écriture ;
  elle ne conditionne plus, à elle seule, le contenu de la réponse (§0 n°2).
- **`caisse.gerer` n'implique pas automatiquement `caisse.voir_z`/`caisse.voir_ecart` dans le code** —
  ⚠ HYPOTHÈSE actée par la spec elle-même (§3 tableau, note administrateur) : c'est une composition de
  rôle M8 (assignation de permissions à un `Role`), hors périmètre technique de ce plan. Un administrateur
  qui, aujourd'hui, tient son accès complet via un rôle portant `caisse.*` (wildcard, cf. fixtures
  `VenteFixtures`) **continue de tout voir automatiquement** dès que `caisse.voir_z`/`caisse.voir_ecart`
  existent en base : `CalculateurDroits::autorise()` traite `caisse.*` comme couvrant **toute** action du
  module `caisse`, actions nouvelles incluses, sans qu'aucune ligne `sec_permission` supplémentaire ne
  soit requise pour ce cas précis (RG-CAISSEZ-09, rétrocompatibilité automatique par construction du
  moteur de droits existant — aucune modification de `CalculateurDroits` nécessaire).
- **Voters** — aucun voter custom : `PermissionVoter` (socle) suffit pour la `security:` déclarative des
  opérations API Platform (§2) ; la gradation **au sein** d'une même opération (`POST …/cloturer`) est
  portée par un appel programmatique `Security::isGranted()` dans le processor (§0 n°2, §4), pas par un
  voter dédié — cohérent avec le patron déjà utilisé pour `AjoutLigneProcessor::isGranted('PERM',
  'vente.forcer_prix')`.

---

## 4. Point d'insertion précis — `App\Caisse\State\CloturerSessionProcessor`

Constructeur : ajoute `Security $security` et `JournalAudit $journal` aux dépendances existantes
(`EntityManagerInterface`, `LecteurCorps`, `PanierCalculateur`, `ScellementHandler`).

```php
public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly LecteurCorps $lecteur,
    private readonly PanierCalculateur $calc,
    private readonly ScellementHandler $scellement,
    private readonly Security $security,       // + ajouté
    private readonly JournalAudit $journal,     // + ajouté
) {}
```

Trois insertions dans `process()`, **le calcul existant (lignes 55 à 135 du code actuel) reste identique
au caractère près** entre les points (b) et (c) :

**(a) Garde « comptage espèces requis » — RG-CAISSEZ-04, CA-10.** Juste après la construction de
`$comptesSaisis` (juste avant le calcul de `$comptages`/`$ecartTotal`), donc **avant tout `persist()`** :

```php
$peutVoirZ = $this->security->isGranted('PERM', 'caisse.voir_z');   // calculé une fois, réutilisé en (c)

if (!$peutVoirZ && !\array_key_exists('especes', $comptesSaisis)) {
    throw new UnprocessableEntityHttpException('Comptage espèces requis (comptage aveugle).');
}
```

Un porteur de `caisse.voir_z` n'est **pas** concerné par cette garde (comportement actuel inchangé :
comptage manquant = réputé conforme, RG-CAISSEZ-04).

**(b) Création conditionnelle de `AlerteEcartCaisse` — RG-CAISSEZ-05/06/07.** Juste après
`$this->em->persist($cloture)` et le scellement NF525 (inchangés), **avant** `$data->fermer()` et le
`flush()` final (même transaction) :

```php
$pdv = $data->getPointDeVente();
if ($pdv !== null) {
    $ecartAbs = abs($this->calc->centimes($cloture->getEcartTotal()));
    $toleranceCentimes = $this->calc->centimes($pdv->getToleranceEcartCaisse());
    if ($ecartAbs > $toleranceCentimes) {
        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $alerte = (new AlerteEcartCaisse())
            ->setCloture($cloture)
            ->setSession($data)
            ->setEtablissement($data->getEtablissement())
            ->setEcartMontant($cloture->getEcartTotal())
            ->setToleranceAppliquee($pdv->getToleranceEcartCaisse())
            ->setAuteurCloture($auteur)
            ->setHorodatage($cloture->getHorodatage());
        $this->em->persist($alerte);

        $this->journal->enregistrer(
            'caisse.alerte_ecart',
            'AlerteEcartCaisse',
            (string) $alerte->getId(),
            $data->getEtablissement()?->getId(),
            $auteur->getEmail(),
        );
    }
}
```

`$pdv === null` → aucune alerte créée, cf. Décision §0 n°6 (cas défensif). Comparaison stricte `>` (pas
`≥`) : RG-CAISSEZ-05bis / cas limite « écart nul exact avec tolérance nulle → pas d'alerte ».

**(c) Branchement de la réponse HTTP — RG-CAISSEZ-01/02.** Remplace le seul `return new JsonResponse([...])`
final (après `$data->fermer()`, `setEtat(Securisee)` et `$this->em->flush()`, tous inchangés) :

```php
if ($peutVoirZ) {
    return new JsonResponse([
        // --- corps actuel, strictement inchangé ---
        'cloture' => (string) $cloture->getId(),
        'session' => $data->getNumero(),
        'etatSession' => $data->getEtat()->value,
        'totalVentes' => $cloture->getTotalVentes(),
        'totalRemboursements' => $cloture->getTotalRemboursements(),
        'comptages' => $comptages,
        'ecartTotal' => $cloture->getEcartTotal(),
        'versement' => $versement,
        'fondReporte' => $fondReporte,
        'etatDeRegie' => $cloture->getEtatDeRegie(),
    ], JsonResponse::HTTP_OK);
}

// Comptage aveugle (RG-CAISSEZ-02) : accusé minimal, aucun champ monétaire.
return new JsonResponse([
    'cloture' => (string) $cloture->getId(),
    'session' => $data->getNumero(),
    'etatSession' => $data->getEtat()->value,
    'horodatageCloture' => $cloture->getHorodatage()->format(\DateTimeInterface::ATOM),
    'message' => 'Caisse fermée.',
], JsonResponse::HTTP_OK);
```

Aucune autre ligne du fichier n'est modifiée : la totalité du calcul (théorique, remboursements,
comptages, écart, `etatDeRegie`, scellement NF525, fermeture de session, sécurisation de la caisse) est
exécutée et persistée **identiquement quel que soit `$peutVoirZ`** (RG-CAISSEZ-01/09).

---

## 5. Migrations

Une seule migration additive, même patron que `Version20260818120000` (module Autorisation) :

1. **Colonne** — `ALTER TABLE caisse_point_de_vente ADD tolerance_ecart_caisse NUMERIC(10, 2) DEFAULT
   '0.00' NOT NULL` (RG-CAISSEZ-08). `down` : `ALTER TABLE caisse_point_de_vente DROP tolerance_ecart_caisse`.
2. **Table** — `CREATE TABLE caisse_alerte_ecart (id BINARY(16) NOT NULL, cloture_id BINARY(16) NOT NULL,
   session_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, ecart_montant NUMERIC(10,2) NOT
   NULL, tolerance_appliquee NUMERIC(10,2) NOT NULL, auteur_cloture_id BINARY(16) NOT NULL, horodatage
   DATETIME NOT NULL, INDEX idx_alerte_session (session_id), INDEX idx_alerte_etablissement
   (etablissement_id), INDEX idx_alerte_auteur (auteur_cloture_id), UNIQUE INDEX uniq_alerte_cloture
   (cloture_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4`, + FK vers `caisse_cloture_z(id)`,
   `caisse_session(id)`, `org_etablissement(id)`, `sec_utilisateur(id)`. `down` : `DROP FOREIGN KEY` ×4 puis
   `DROP TABLE caisse_alerte_ecart`.
3. **Permissions** (idempotent, patron `INSERT IGNORE` déjà utilisé par `Version20260818120000`/
   `Version20260817173400`) — `INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, 'caisse',
   'voir_z'), (?, 'caisse', 'voir_ecart')`. `down` : `DELETE FROM sec_permission WHERE module = 'caisse'
   AND action IN ('voir_z', 'voir_ecart')`.

Réversible dans l'ordre inverse (permissions → table → colonne). Suppose les migrations socle (L0) et L2
(Vente/Caisse, `caisse_point_de_vente`/`caisse_cloture_z`/`caisse_session`) déjà jouées. Nom de fichier
définitif attribué à l'implémentation (convention `VersionYYYYMMDDHHMMSS`, postérieure à la dernière
migration existante `Version20260818120000`).

**Hors migration DB** — aucune extension d'`AuditWriteSubscriber::CLASSES_SURVEILLEES` (Décision §0
en-tête : traçabilité par appel explicite `JournalAudit`, pas par le listener générique).

---

## 6. Tests (PHPUnit + `ApiTestCase`)

### 6.1 Fixtures additives — `App\Caisse\DataFixtures\CaisseClotureRoleFixtures`

`DependentFixtureInterface` → `[SocleFixtures::class, VenteFixtures::class]`. Ajoute, en plus des
permissions `caisse.voir_z`/`caisse.voir_ecart` (rechargées à chaque test, comme toutes les fixtures) :
- **Rôle « Caissier »** = `{caisse.cloturer}` uniquement → utilisateur `caissier@itcotation.com`, affecté
  **uniquement** sur l'établissement A (Piscine A).
- **Rôle « Régisseur »** = `{caisse.cloturer, caisse.voir_z, caisse.voir_ecart, caisse.lire, vente.lire}`
  → utilisateur `regisseur@itcotation.com`, affecté sur l'établissement A.
- **Rôle « Régisseur B »** (même permissions que « Régisseur ») → utilisateur `regisseur-b@itcotation.com`,
  affecté **uniquement** sur l'établissement B (Patinoire B, existant dans `SocleFixtures`), **aucune**
  affectation sur A (nécessaire pour CA-7).

### 6.2 Base de test — `App\Tests\Caisse\CaisseClotureRoleApiTestCase`

Autonome (§0 n°8) : recrée le schéma, charge `SocleFixtures` + `OffreFixtures` + `VenteFixtures` +
`CaisseClotureRoleFixtures` ; expose des raccourcis `caissierSurA()`, `regisseurSurA()`, `regisseurSurB()`
(même forme que `VenteApiTestCase::adminSurA()`) + réutilise `ouvrirSession()`/`idPointDeVente()` (copiés
ou factorisés selon convenance d'implémentation, décision laissée à T-implémentation).

### 6.3 Table des tests

| Test | Type | Couvre |
|---|---|---|
| Caissier (`caisse.cloturer` seul) clôture avec `comptages: [{moyen: especes, compte: …}]` → 200, corps = `{cloture, session, etatSession, horodatageCloture, message}` **uniquement** — absence stricte (`array_key_exists` false, pas juste `null`) de `totalVentes`/`comptages`/`ecartTotal`/`versement`/`fondReporte`/`etatDeRegie` | API | CA-1, RG-CAISSEZ-01/02 |
| Après la clôture du test précédent, un régisseur (`caisse.voir_z`) fait `GET /clotures-z/{id}` → 200, retrouve `totalVentes`/`comptages`/`ecartTotal` calculés et persistés (donnée jamais perdue) | API | CA-2, RG-CAISSEZ-01 |
| PDV `toleranceEcartCaisse = 0.00` (défaut), clôture produisant un écart `-5.00` → `AlerteEcartCaisse` créée dans la même réponse HTTP (assertion sur l'existence immédiate, pas de tâche différée), portant établissement/montant/clôture, + `EntreeAudit(action: 'caisse.alerte_ecart', cibleType: 'AlerteEcartCaisse')` présent | API | CA-3, RG-CAISSEZ-05/06/07 |
| PDV `toleranceEcartCaisse = 5.00` (via `PATCH` admin, `caisse.gerer`), écart `3.00` → **aucune** `AlerteEcartCaisse` (assert collection vide pour cette session) | API | CA-4, RG-CAISSEZ-05bis |
| Régisseur (`caisse.voir_z`) clôture → réponse contient `totalVentes`/`comptages`/`ecartTotal`/`etatDeRegie` complets, identique au comportement `testCa14ClotureZ` existant (non-régression explicite, mêmes valeurs numériques) | API | CA-5, RG-CAISSEZ-09 |
| Caissier (sans `caisse.voir_z`) fait `GET /clotures-z/{id}` et `GET /clotures-z/{id}/etat-regie` sur la clôture du régisseur → 403 les deux | API | CA-6, RG-CAISSEZ-03 |
| Alerte créée pour l'établissement A ; `regisseur-b@itcotation.com` (affecté uniquement sur B, `caisse.voir_ecart`) liste `GET /alertes-ecart-caisse` → collection ne contient pas l'alerte de A | API | CA-7, RG-SOCLE-05 |
| Session déjà close (peu importe le rôle, caissier ou régisseur) → 2e tentative de clôture → 409, inchangé | API | CA-8, non-régression RG-M2-06 |
| Caissier compte espèces **+** un moyen manuel (ex. chèque) → écart calculé sur l'ensemble des moyens (comme aujourd'hui), réponse reste strictement minimale (aucune fuite quel que soit le nombre de lignes) | API | CA-9, RG-CAISSEZ-04 |
| Caissier clôture **sans** ligne `comptages` pour `especes` → 422 « comptage espèces requis » ; un régisseur dans le même cas (sans `especes`) → 200 (comptage manquant réputé conforme, comportement actuel inchangé) | API | CA-10, RG-CAISSEZ-04 |
| `PointDeVente` nouvellement créé (`Post`, `caisse.gerer`), sans `toleranceEcartCaisse` transmis → valeur persistée `0.00` | API | CA-11, RG-CAISSEZ-08 |
| `GET /clotures-z` (collection) avec un caissier sans `caisse.voir_z` → 403 (pas seulement `Get` unitaire) | API | RG-CAISSEZ-03, complète CA-6 |
| Unitaire : comparaison écart/tolérance en centimes — `abs(-500) > centimes('0.00')` vrai, `abs(300) > centimes('5.00')` faux, `abs(500) > centimes('5.00')` faux (égalité stricte, pas d'alerte) | Unit | RG-CAISSEZ-05/05bis, cas limite « écart nul exact » |
| Non-régression : `testCa14ClotureZ` et les autres tests existants de `SessionTest`/`Nf525ApiTest`/`ImmuabiliteTest`/`CloisonnementTest` (M2) passent sans modification — exécutés tels quels après le changement de `security:` sur `ClotureZ` (l'utilisateur `admin@itcotation.com` porte `caisse.*`, donc `caisse.voir_z` automatiquement, §3) | API (existant, non modifié) | RG-CAISSEZ-09 |

---

## 7. Tâches (voir tasks-caisse-cloture-role.md)

T1 Entité `AlerteEcartCaisse` (+ getters/setters, `Groups(['alerte_ecart:read'])`) + champ
`PointDeVente::toleranceEcartCaisse` (getter/setter, `Groups(['pdv:read','pdv:write'])`,
`Assert\PositiveOrZero`) →
T2 Migration additive (colonne + table + FK + permissions `caisse.voir_z`/`caisse.voir_ecart`, §5) →
T3 `#[ApiResource]` `AlerteEcartCaisse` (`GetCollection`/`Get` uniquement, `security: caisse.voir_ecart`,
filtres `etablissement`/`session`) + entrée `CHEMINS` dans `PerimetreVenteExtension` (§1.4) →
T4 Bascule `security:` de `ClotureZ` (`GetCollection`/`Get`/`.../etat-regie`) : `caisse.lire` →
`caisse.voir_z` (§2) →
T5 `CloturerSessionProcessor` — injection `Security`/`JournalAudit`, garde (a) comptage espèces requis,
création (b) `AlerteEcartCaisse` + audit, branchement (c) réponse minimale/complète (§4) — priorité aux
tests unitaires de la comparaison centimes/tolérance avant l'intégration API →
T6 Fixtures `CaisseClotureRoleFixtures` (rôles Caissier/Régisseur/Régisseur B, permissions, affectations)
→
T7 Base de test `CaisseClotureRoleApiTestCase` + suite de tests CA-1 à CA-11 (§6.3, priorité CA-1/CA-2/
CA-6 qui verrouillent la gradation de réponse avant les tests d'alerte) →
T8 Exécution complète de la suite existante M2 (`SessionTest`, `Nf525ApiTest`, `ImmuabiliteTest`,
`CloisonnementTest`, `PaiementTest`) sans modification — non-régression RG-CAISSEZ-09 — et mise à jour de
ce plan si divergence d'implémentation constatée.

---

## 8. Risques / à valider

1. **Champ `statut` sur `AlerteEcartCaisse` mentionné dans le cadrage informel mais absent du tableau §5
   de la spec détaillée** (§0 n°4) — ce plan suit la spec écrite (pas de `statut`, pas d'acquittement en
   v1) ; à faire confirmer explicitement avant implémentation pour éviter un aller-retour si le client
   attendait effectivement un statut d'acquittement dès cette version.
2. **US-CAISSEZ-01 à 04 / RG-CAISSEZ-01 à 09 non validées/numérotées officiellement** dans le backlog —
   même réserve que `spec-caisse-cloture-role.md` (préambule) et que les modules récents (Autorisation,
   Stock, Personnel) : à faire inscrire/valider avant planification effective.
3. **Défaut de tolérance à `0,00 €`** (RG-CAISSEZ-08) — tout écart non nul déclenchera une alerte tant
   qu'aucun établissement n'aura paramétré de tolérance explicite ; risque de volume d'alertes élevé en
   phase de rodage si l'arrondi de caisse est courant — point ouvert déjà signalé par la spec elle-même
   (§9), à confirmer avec le client avant mise en production.
4. **Portée de la tolérance au point de vente (pas à l'établissement)** — ⚠ HYPOTHÈSE reprise de la spec
   (RG-CAISSEZ-08) ; changement de portée possible sans refonte de la règle si le client préfère un
   paramétrage unique par établissement (déplacer simplement le champ).
5. **Canal de l'alerte in-app uniquement** — aucun module `Notification` transverse n'existe dans le code
   (confirmé par grep, cf. spec §9) ; si le client exige un e-mail immédiat au régisseur en cas d'écart,
   cela nécessite un module transverse hors périmètre de ce plan.
6. **`AlerteEcartCaisse` non créée si `SessionCaisse::getPointDeVente() === null`** (§0 n°6) — cas
   normalement impossible en usage réel (`JoinColumn(nullable: false)` sur `SessionCaisse.pointDeVente`)
   mais le type PHP reste nullable ; choix défensif documenté plutôt qu'un comportement implicite non
   testé.
7. **Composition des rôles M8 hors périmètre** — quelles permissions concrètes portent réellement les
   rôles « Caissier »/« Régisseur »/« Administrateur du site » chez chaque client aujourd'hui configurés
   reste une tâche de paramétrage back-office, non couverte par ce plan technique (cf. spec §9).
8. **Rétrocompatibilité automatique via wildcard `caisse.*`** (§3) — repose sur le comportement déjà
   existant et non modifié de `CalculateurDroits::autorise()` ; à revérifier par un test explicite (dernière
   ligne du §6.3) plutôt que supposé, car c'est le mécanisme exact qui garantit qu'aucun client existant
   ne perd l'accès au Z le jour du déploiement de cette évolution.
9. **NF525/comptable** — le scellement de `ClotureZ` (`ScellementHandler`) n'est pas touché ;
   `AlerteEcartCaisse` n'est **pas** elle-même une écriture scellée NF525 (c'est un signal interne, pas un
   document de régie) — à confirmer par un expert NF525 uniquement si le client demandait un jour que
   l'alerte elle-même soit opposable/archivée au même titre qu'un Z (hors périmètre v1, non demandé par
   la spec).
10. **`caisse.voir_ecart` sans `caisse.voir_z` = profil de supervision multi-site sans détail comptable**
    (§7 cas limite spec) — cas d'usage assumé par la spec comme cohérent mais non explicité par le client
    à l'origine ; à valider que ce croisement de droits correspond à un besoin réel avant de le proposer
    en configuration back-office.
