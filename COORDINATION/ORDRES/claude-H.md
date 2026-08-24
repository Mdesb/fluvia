# Ordres pour `claude-H`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais — c'est ce qui garantit
> qu'il n'y a jamais de conflit de fusion dessus.

---

## 2026-08-24 12:40 · Premier ordre — le coffre à jetons avant les réseaux

Tu construis la publication sociale : publier une fois, diffuser partout, mesurer. Deux usages, un
seul module — celui des clients de Maxime, et le sien.

### Commence par SOC-1, et surtout par le coffre

Le modèle de publication est la partie facile. **Le coffre à jetons est la partie qui compte** : tu vas
stocker des jetons d'accès à des comptes sociaux de tiers, c'est-à-dire de quoi publier au nom de
quelqu'un d'autre.

Trois exigences non négociables, tirées de ce que nous avons appris cette semaine :

1. **Chiffré au repos, clé depuis l'environnement, sans valeur par défaut.** Quatre occurrences du même
   défaut ont déjà été corrigées dans ce dépôt — trois chaînes NF525 et la GED. Le garde-fou des
   secrets refusera ton commit si tu poses une clé en dur, et il aura raison.
2. **Cloisonné par établissement**, contrôlé sur l'entité résolue et non sur le fichier. Seize IDOR ont
   été trouvés ici en cinq jours, tous de la même forme : une entité résolue depuis l'entrée client,
   jamais confrontée au périmètre.
3. **Un jeton ne sort jamais d'une réponse d'API**, ni d'un événement, ni d'un journal. La GED a le bon
   précédent : `document.public_link_issued` transporte l'échéance et l'identifiant, **jamais le
   jeton**.

### Puis SOC-2 — et uniquement des réseaux ouverts

Mastodon et Bluesky. **Ne touche pas aux adaptateurs Meta** : SOC-4 est en statut `EXTERNE`, elle
attend une vérification d'entreprise qui attend elle-même l'immatriculation de la société. C'est au
registre `BLOQUEURS-EXTERNES.md`, entrée E-1.

C'est D19 : ce qui dépend d'un tiers est consigné, jamais attendu — et viser d'abord les réseaux
ouverts est exactement l'application de cette règle.

### File, reprises, quotas

Une publication qui échoue se rejoue ; une plateforme qui limite le débit se respecte. Sers-toi du bus
asynchrone (D7-bis, messenger avec transport Doctrine) plutôt que d'écrire ta propre file.
