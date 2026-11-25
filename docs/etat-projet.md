# Tableau de bord — Fluvia

> **Vue régénérée par `/point-projet`** — ne pas éditer à la main, ne rien y stocker d'introuvable ailleurs.
> Dernière mise à jour : 2026-11-25 (cycle complet — séance des priorités #369, #370, #365, #363)

## Où on en est
Les priorités fixées (#369 → #370 → #365 → #363) sont toutes construites et ouvertes en PR brouillon. Le paiement en ligne passe à un compte par exploitant (Stripe et PayFiP), et l'anonymisation RGPD efface désormais aussi l'historique d'audit. Reste D6-A (#365), bloqué par le format de la convention CAMVS.

## Dernières livraisons (PR brouillon, à fusionner)
| Date | Livré | Impact pour l'utilisateur |
|------|-------|---------------------------|
| 2026-11-25 | RGPD : caviardage de l'audit (#378) | Un usager anonymisé n'est plus lisible dans l'historique ; une commande rattrape les fiches déjà traitées |
| 2026-11-25 | Numéro PayFiP par régie (#377) | Chaque collectivité encaisse sur son propre compte ; rattacher un établissement exige `payment.configure` |
| 2026-11-21 | Clé Stripe par exploitant (#376) | Chaque exploitant privé encaisse sur son compte Stripe, saisi à l'écran |
| 2026-11-21 | Signature des notifications Stripe (#375) | Seules les notifications réellement émises par Stripe sont traitées |
| 2026-11-21 | Alerte de scellement NF525 (#374) | Un refus de scellement prévient l'administrateur et l'éditeur |
| 2026-11-21 | Ordre des clés NF525 (#373) | Les clés de scellement sont vérifiées dans le bon ordre |

## Reste à faire (par module)
- **Paiement en ligne (#365)** — D6-A, en attente du format CAMVS. Empreinte de clé Stripe à l'initiation (hors lot).
- **RGPD** — lot suivant : `auteur` quand l'usager modifie sa fiche ; audit de son utilisateur, de sa famille, de ses bénéficiaires et de son compte client ; journal des fusions.
- **Aide** — `borne-libre-service.md` (`publicCible: administrateur`) fait échouer `ImporterAideCommandTest` sur `main` : correctif rapide prêt.

## ⏳ En attente de toi (décisions / validations)
- **#373** : mesure `check-keys` en préprod.
- **#374** : arbitrer les 10 points ouverts.
- **#372** : passer en « prête ».
- **Correctif `borne-libre-service.md`** : ton accord.
- **Ordre de fusion** : #375 → #376 → #377 (empilées) ; #378 indépendante (base `main`).
- **Déploiement** : poser `STRIPE_WEBHOOK_SECRET` (témoin : 404 « inconnu ») ; retirer `PAYFIP_NUMCLI` et saisir les numéros PayFiP ; compter `audit_entree` avant la migration d'index ; lancer le rattrapage RGPD (`--dry-run` d'abord) ; relire à l'écran les sections Stripe et PayFiP.
- **Registre des traitements** : le valider (D5 de #353) et fixer la durée de conservation de l'audit.

## Santé du projet
| Indicateur | État | Dernier contrôle |
|------------|------|------------------|
| CI (#373–#377) | 🟢 verte ; #378 en cours | 2026-11-25 |
| Suite PHP (locale) | 🟢 sur les dossiers touchés ; 🟠 1 échec préexistant sur `main` (`ImporterAideCommandTest`) | 2026-11-25 |
| Garde-fous (locaux) | 🟠 3 échecs attendus (PHP 8.3 de l'hôte) | 2026-11-25 |
| Cohérence (`/verifier-coherence`) | non lancé | — |
| Performance requêtes (`/audit-donnees`) | non lancé | — |
