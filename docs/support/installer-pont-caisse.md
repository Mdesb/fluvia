# Installer le pont de caisse (terminal relié Caisse-AP, tiroir-caisse)

Pour : un poste de caisse sous Windows 11 dont le terminal de paiement parle **Caisse-AP sur IP**, ou
dont le tiroir-caisse doit s'ouvrir tout seul.
But : que le montant parte au terminal sans ressaisie, que le résultat revienne dans la caisse, et que
le tiroir s'ouvre après un paiement en espèces.

La procédure complète (installation, réglages, essai) est dans le dépôt :
`bridge/caisse-ap/README.md`, section « Installation sur le poste de caisse ». Elle est identique sur
tous les sites ; seuls changent l'adresse du terminal et l'adresse de Fluvia.

## Si ça ne marche pas

| Ce qu'on voit dans la caisse | Cause probable | Que faire |
|---|---|---|
| « Le pont de caisse ne répond pas sur ce poste » | Le pont n'est pas démarré | Fermer la session Windows et la rouvrir ; sinon `node bin\fluvia-bridge.js start` dans `C:\FluviaPont` et lire le message |
| Le pont démarre puis dit « allowedOrigins est vide » | `config.json` pas rempli | Mettre l'adresse de Fluvia, exactement comme dans la barre d'adresse |
| « Origine non autorisée » | Adresse de Fluvia différente dans `allowedOrigins` (http/https, www, barre finale) | La recopier depuis la barre d'adresse |
| « Terminal injoignable : rien n'a été envoyé » | Mauvaise IP ou port du terminal, terminal éteint ou pas en mode Caisse-AP IP | Vérifier `terminal.host` / `terminal.port`, et le réglage du terminal avec son installateur |
| « Issue du terminal non signée par le pont de ce point de vente » | La clé collée dans Paramètres n'est pas celle de ce poste | `node bin\fluvia-bridge.js key`, recopier dans Paramètres › Points de vente |
| « Le terminal traite déjà un autre paiement » | Un paiement est en cours sur le terminal | Le terminer ou l'abandonner sur le terminal (touche rouge) |
| Le tiroir ne s'ouvre pas, « Tiroir-caisse non ouvert » | `drawer` mal réglé, imprimante non partagée, tiroir sur la broche 5 | `node bin\fluvia-bridge.js drawer` pour essayer ; voir le tableau « Le tiroir-caisse » du README |
| Le paiement reste « à déclarer » | Liaison coupée pendant le paiement : la carte a peut-être été débitée | Lire le terminal et déclarer ce qu'il affiche ; ne jamais réencaisser avant |
