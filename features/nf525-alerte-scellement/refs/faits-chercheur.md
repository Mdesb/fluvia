# Faits établis (chercheur, 18/11/2026) — nf525-alerte-scellement (#370)

Lu sur la branche `feature/nf525-ordre-cles` (contient #369). VERIFIED = lu dans le code ; UNVERIFIED = non mesuré.

## Ce qui existe
- VERIFIED — **Centre de notifications** : entité `Platform\Entity\Notification` (table `platform_notification`, une ligne par destinataire et établissement, gravité info/attention/critique, titre, texte, écran, `lue`). Lecture `GET /api/notifications`, acquittement `POST /api/notifications/{id}/lue` et `tout-lu`. Cloche du frontal (`frontend/src/components/Cloche.jsx`, interrogée toutes les 60 s). Cloisonnement par destinataire et par établissement actif (`NotificationScopeExtension`).
- VERIFIED — Alimentation par une table fermée de règles (`NotificationRule`, 7 règles) et `NotifyOnDomainEvent`, qui **ne flush pas** : la notification naît dans la transaction de l'émetteur. Critère d'admission : événement rare, qui appelle le geste d'une personne, et mène à un écran.
- VERIFIED — Destinataires résolus par droit (`NotificationRecipientResolver`, « on notifie ceux qui peuvent faire le geste »). L'« administrateur » au sens du dépôt = titulaire de `securite.gerer` sur l'établissement.
- VERIFIED — Précédent de dédoublonnage : `TreasuryCashAlert` (une seule alerte ouverte par établissement, index unique sur colonne virtuelle). Précédent d'agrégation : `access.capacity_exceeded` (une cloche par lot, pas par passage, D121).
- VERIFIED — Règle du relevé `claude-H` (DECISIONS.md) : une liste de choses à traiter s'affiche avec le geste qui les traite, sinon c'est du décor.

## Ce qui manque
- VERIFIED — L'alerte NF525 d'aujourd'hui n'est qu'un `logger->critical` (SealKeyring) : rien n'est persisté, personne ne la lit.
- VERIFIED — Le scellement tourne dans une transaction (`ValiderVenteService`) **annulée** quand le refus est levé : une alerte écrite dedans serait perdue. Aucun mécanisme du dépôt n'écrit une trace qui survit à un rollback (recherche non exhaustive).
- VERIFIED — Messenger : transport `async` (`MESSENGER_TRANSPORT_DSN`, valeur UNVERIFIED), worker `messenger:consume async` en préprod et prod.
- VERIFIED — E-8 : aucun courriel ne sort (`MAILER_DSN=null://null`). Seule la notification dans l'application est réelle.

## Rattachement des refus
- Vente : point de vente → établissement (champ nullable).
- Compta et facturation : profil exploitant, qui couvre **plusieurs** établissements (principal nullable + rattachés) : pas d'établissement unique.
- `app:nf525:check-keys` : aucune chaîne d'établissement, c'est un contrôle de déploiement.

## Message rendu au caissier
- VERIFIED — Le 409 contient le nom de variable d'environnement, l'UUID du point de vente, le numéro de maillon, des identifiants de clé et la procédure ; la quarantaine hors ligne le renvoie tel quel au poste (`SynchroOperationsProcessor`).
- UNVERIFIED — Ce que l'écran de caisse affiche du 409 (frontal non lu).

## Risque d'inondation
- Une chaîne bloquée refuse **chaque** vente : sans dédoublonnage, une alerte par tentative et par destinataire. `Notification` n'a pas de clé de dédoublonnage.
