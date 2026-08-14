# Logiciel de billetterie & contrôle d'accès

Plateforme de billetterie + contrôle d'accès pour piscines, patinoires, salles de sport,
centres de padel et musées (collectivités en régie, DSP, groupes privés). Hébergement France.

**Stack :** PHP 8.4 · Symfony 7 · API Platform · Doctrine · MariaDB 11.4 · Docker.

## Démarrer (dev)

```bash
docker compose up -d
docker compose exec -T php php bin/console cache:warmup
```

- API / santé : http://localhost:8080/health
- API Platform (doc interactive) : http://localhost:8080/api
- Base MariaDB : `localhost:3306` (base `app`, user `app`/`app`).

Commandes Symfony : `docker compose exec -T php php bin/console <cmd>`
Tests : `docker compose exec -T php vendor/bin/phpunit`

> Windows : piloter Docker via PowerShell (les chemins avec espaces passent mal en Git-Bash pour `docker run -v`).

## Structure

```
app/            Application Symfony (src/, config/, tests/…)
docker/         Images et config (php/Dockerfile, nginx/default.conf)
specs/          Méthode SDD : constitution, specs/plans/tâches par lot, templates
.claude/        Kit SDD : agents (analyste/architecte/dev/revue) + commande /module
analyse-existant/, *.html   Analyses & artefacts de cadrage (CDC, backlog, démo)
```

## Méthode : SDD (Spec-Driven Development)

Toute fonctionnalité suit le pipeline **spec → plan → implémentation → revue**, piloté par la
commande `/module <US-Lx-nn | Mx>` et les agents de `.claude/agents/`. Le document de référence
est [`specs/constitution.md`](specs/constitution.md). Traçabilité : règles `RG-Mx-nn`, stories
`US-Lx-nn`. Le plan de construction va du socle technique (**L0**) aux verticales.
