# Plan technique — <titre> (`<US/Mx>`)

- **Spec source :** specs/<lot>/spec-<slug>.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB

## 1. Entités & schéma
| Entité (`App\<Module>\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
> id = UUID (`symfony/uid`). Rattachement multi-entités : <Établissement/Espace…>.

## 2. API (API Platform)
| Ressource | Opérations | security: | Groupes sérialisation | Filtres |
|---|---|---|---|---|

## 3. Sécurité & droits
- Permissions requises : <module × action>
- Voters : …

## 4. Migrations
- <ce qui est créé/modifié>

## 5. Tests
| Test | Type | Couvre |
|---|---|---|

## 6. Tâches (voir tasks-<slug>.md)
- T1 … · T2 … (ordonnées)

## 7. Risques / à valider
- …
