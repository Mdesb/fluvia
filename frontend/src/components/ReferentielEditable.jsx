import { useCallback, useEffect, useState } from 'react'
import { membres } from '../api/client.js'
import Modal from './Modal.jsx'

// Éditeur de référentiel — un composant, N référentiels.
//
// POURQUOI GÉNÉRIQUE. L'écran Paramètres affichait cinq listes en lecture seule, chacune avec son
// propre rendu. Les rendre modifiables une par une aurait multiplié par cinq le même formulaire, la
// même gestion d'erreur et le même piège. Un descripteur par référentiel suffit, et le jour où l'on
// améliore la confirmation de suppression, elle s'améliore partout.
//
// CE QUE CE COMPOSANT REFUSE DE FAIRE, et c'est le plus important :
//
// 1. Il n'affiche jamais un tableau vide sans expliquer. « Aucun élément » est un cul-de-sac, et
//    c'est précisément le moment où quelqu'un qui découvre le logiciel a le plus besoin d'aide.
//    Un référentiel vide dit à quoi il sert, ce qui se passera s'il reste vide, et propose le geste.
//
// 2. Il ne demande jamais « Êtes-vous sûr ? ». Cette question ne rend service à personne : elle
//    n'apporte aucune information et déplace la responsabilité sans donner de quoi décider. La
//    confirmation dit ce que la suppression CASSE, en langage courant.
//
// 3. Il ne nomme jamais un champ par ce qu'il stocke. Chaque champ porte, si besoin, une phrase qui
//    dit ce qui change pour le client ou pour la caisse — pas ce qui change en base.

// Ce qu'un champ de formulaire doit recevoir quand la valeur vient du serveur.
//
// Une relation est sérialisée en objet ; un `<select>` ne compare que des chaînes. On rend donc
// l'IRI (`@id`) quand elle existe, l'identifiant brut à défaut.
//
// CE QUE CETTE FONCTION NE SAIT PAS FAIRE, ET QUI RESTE LE TRAVAIL DE `versValeur`.
//
// Quand le serveur sérialise la relation SANS `@id` — juste `{ id, nom }` —, on ne peut pas
// reconstruire l'IRI : le chemin de la ressource n'est pas déductible de la valeur. La fonction rend
// alors l'identifiant nu, qui ne correspondra à aucune option, et le serveur refusera l'envoi.
//
// Ce n'est pas une réparation, c'est un déplacement — et c'est tout l'intérêt : **le pire cas
// devient bruyant au lieu d'être silencieux.** Avant, un oubli effaçait une relation sans que
// personne ne s'en aperçoive ; maintenant il produit un refus immédiat, sur l'écran, devant celui
// qui vient d'enregistrer. C'est `versValeur` qui règle ce cas-là, comme pour `region`.
function versChampSimple(valeur, champ) {
  if (valeur === null || valeur === undefined) return champ.type === 'bool' ? false : ''
  if (typeof valeur === 'object') return valeur['@id'] || valeur.id || ''
  return valeur
}

export default function ReferentielEditable({ descripteur, peutEcrire, onEcrit }) {
  // ⚠ `null` = PAS LU, `[]` = LU ET VIDE. Ce composant porte la liste et l'etat vide de DOUZE
  // referentiels. Sur une lecture refusee, il affichait `siVide` — un texte ecrit pour « il n'y en
  // a pas » — la ou la reponse honnete est « on n'a pas pu regarder ». Sur les etablissements, ca
  // donnait « Aucun etablissement n'est accessible depuis ce compte », qui se lit comme un
  // probleme de droits.
  const [lignesLu, setLignesLu] = useState(null)
  // ⚠ `null` NE SORT PAS D'ICI. Il dit « pas lu » et rien d'autre ; tout l'aval — y
  // compris ce qui part en prop vers un enfant — lit un tableau. Sans cette ligne il faut
  // trouver chaque usage, et un usage manque ne se signale que par un ecran mort.
  const lignes = lignesLu || []
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [erreurEdition, setErreurEdition] = useState(null)
  const [succes, setSucces] = useState(null)
  const [edition, setEdition] = useState(null) // { ligne, valeurs } — ligne nulle = création
  const [enCours, setEnCours] = useState(false)

  const { titre, aQuoiCaSert, siVide, champs, colonnes, charger, creer, modifier, supprimer, consequenceSuppression } =
    descripteur

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      setLignesLu(membres(await charger()))
    } catch (e) {
      setErreur(e.message)
      // On ne garde pas la liste precedente : elle donnerait l'etat d'avant pour celui
      // d'aujourd'hui, ce qui est pire qu'une absence annoncee.
      setLignesLu(null)
    } finally {
      setChargement(false)
    }
  }, [charger])

  useEffect(() => {
    recharger()
  }, [recharger])

  function ouvrirCreation() {
    const valeurs = {}
    champs.forEach((c) => {
      if (c.defaut !== undefined) valeurs[c.nom] = c.defaut
      else if (c.type === 'bool') valeurs[c.nom] = true
      else if (c.type === 'choix-multiples') valeurs[c.nom] = []
      else if (c.type === 'choix') valeurs[c.nom] = c.options[0]?.valeur ?? ''
      else valeurs[c.nom] = ''
    })
    setEdition({ ligne: null, valeurs })
  }

  function ouvrirEdition(ligne) {
    const valeurs = {}
    champs.forEach((c) => {
      // Une relation arrive du serveur en objet (`{ '@id', id, nom }`) alors qu'un `<select>`
      // manipule une chaîne. Sans conversion, le champ s'affiche vide ET l'enregistrement efface la
      // valeur existante sans que personne ne l'ait demandé — un défaut qui, vu de l'écran, ne
      // ressemble à rien : on ouvre une fiche, on corrige un nom, on perd une relation qu'on n'a
      // jamais touchée, et on ne le découvrira que des semaines plus tard.
      //
      // La conversion est donc faite ICI, par défaut, plutôt que confiée à un crochet qu'il faut
      // savoir écrire. `versValeur` reste disponible pour les cas que cette règle ne couvre pas —
      // mais l'oublier ne détruit plus rien.
      if (c.versValeur) valeurs[c.nom] = c.versValeur(ligne)
      else if (c.type === 'choix-multiples') valeurs[c.nom] = Array.isArray(ligne[c.nom]) ? [...ligne[c.nom]] : []
      else if (c.type === 'date') valeurs[c.nom] = (ligne[c.nom] || '').slice(0, 10)
      else valeurs[c.nom] = versChampSimple(ligne[c.nom], c)
    })
    setEdition({ ligne, valeurs })
  }

  async function enregistrer(e) {
    e.preventDefault()
    setErreur(null)
    setSucces(null)
    setErreurEdition(null)
    setEnCours(true)
    try {
      const corps = {}
      champs.forEach((c) => {
        const v = edition.valeurs[c.nom]
        // Symétrique du précédent : un choix laissé vide part à `null` et non en chaîne vide,
        // que le serveur refuse avec un message de désérialisation illisible. `versCorps` reste
        // disponible, mais n'est plus nécessaire pour ce cas-là.
        if (c.versCorps) corps[c.nom] = c.versCorps(v)
        else if (c.type === 'nombre') corps[c.nom] = Number(v)
        else if (c.type === 'bool') corps[c.nom] = !!v
        else if (c.type === 'choix-multiples') corps[c.nom] = Array.isArray(v) ? v : []
        // Une date laissee vide part a `null` et non en chaine vide : le serveur rejette la seconde
        // avec un message de deserialisation que personne ne peut interpreter.
        else if (c.type === 'date') corps[c.nom] = v ? v : null
        else if (c.type === 'choix') corps[c.nom] = v === '' ? null : v
        else corps[c.nom] = v
      })
      if (edition.ligne) await modifier(edition.ligne.id, corps)
      else await creer(corps)
      setSucces(edition.ligne ? 'Modification enregistrée.' : 'Ajout enregistré.')
      onEcrit?.()
      setEdition(null)
      await recharger()
    } catch (err) {
      // LE MESSAGE DU SERVEUR TEL QUEL, ET DANS LA FENÊTRE QUI L'A PROVOQUÉ.
      //
      // Le message brut est gardé parce que c'est lui qui sait ce qui manque. Il partait dans le
      // bandeau de la CARTE, donc DERRIÈRE la modale restée ouverte : sur le formulaire des taux
      // de TVA, la seule chose lisible était la moitié gauche de la bannière qui dépassait du
      // panneau. Constaté à l'écran par la session en revue avec Maxime, pas déduit.
      //
      // Ce composant engendre le formulaire de TOUS les référentiels : la faute était donc dans
      // chacun d'eux à la fois, et la corriger ici les corrige tous.
      setErreurEdition(err.message || "L'enregistrement n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  async function supprimerLigne(ligne) {
    const nom = ligne[champs[0].nom] || 'cet élément'
    if (!window.confirm(`Supprimer « ${nom} » ?\n\n${consequenceSuppression}`)) return
    setErreur(null)
    setSucces(null)
    setEnCours(true)
    try {
      await supprimer(ligne.id)
      setSucces('Suppression effectuée.')
      onEcrit?.()
      await recharger()
    } catch (err) {
      setErreur(err.message || "La suppression n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  return (
    <section className="card" style={{ marginBottom: 16 }}>
      <div className="card-h">
        <h3>{titre}</h3>
        {peutEcrire && creer && (
          <div className="r">
            <button className="btn primary sm" type="button" onClick={ouvrirCreation}>＋ Ajouter</button>
          </div>
        )}
      </div>

      <div className="card-b">
        {/* À quoi ça sert, avant la liste et non après : quelqu'un qui ne sait pas ce qu'il regarde
            ne saura pas non plus quoi en faire. */}
        <p className="hint" style={{ marginTop: 0 }}>{aQuoiCaSert}</p>

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        {chargement ? (
          <div className="center" style={{ minHeight: 80 }}><div className="spinner" /></div>
        ) : (lignes || []).length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>
            <div style={{ marginBottom: 10 }}>
              {lignesLu === null ? <b>Cette liste n’a pas pu être lue : elle est vide parce que la lecture a échoué, pas parce qu’il n’y a rien.</b> : siVide}
            </div>
            {/* ⚠ PAS `primary` : l'en-tete du panneau porte deja « ＋ Ajouter », qui appelle le
                MEME `ouvrirCreation`, et les deux sont visibles ensemble quand la liste est vide —
                mesures a 141 px l'un de l'autre. Deux controles de meme poids pour un seul geste
                divisent l'attention sans rien ajouter.
                C'est celui-ci qu'on attenue et non l'autre : l'en-tete existe dans les deux etats,
                donc sa position s'apprend ; celui-ci disparait des la premiere ligne creee. */}
            {peutEcrire && creer && (
              <button className="btn sm" type="button" onClick={ouvrirCreation}>
                ＋ Créer le premier
              </button>
            )}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  {colonnes.map((c) => (
                    <th key={c.cle} className={c.num ? 'num' : undefined} title={c.aide}>{c.titre}</th>
                  ))}
                  {peutEcrire && <th />}
                </tr>
              </thead>
              <tbody>
                {(lignes || []).map((l) => (
                  <tr key={l.id}>
                    {colonnes.map((c) => (
                      <td key={c.cle} className={c.num ? 'num' : undefined}>
                        {c.rendu ? c.rendu(l) : (l[c.cle] ?? '—')}
                      </td>
                    ))}
                    {peutEcrire && (
                      <td className="num">
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          {modifier && (
                            <button className="btn ghost sm" type="button" onClick={() => ouvrirEdition(l)}>
                              Modifier
                            </button>
                          )}
                          {supprimer && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={enCours}
                              onClick={() => supprimerLigne(l)}
                            >
                              Supprimer
                            </button>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Modal
        open={!!edition}
        onClose={() => { setEdition(null); setErreurEdition(null) }}
        titre={edition?.ligne ? `Modifier — ${titre}` : `Ajouter — ${titre}`}
      >
        {edition && (
          <form onSubmit={enregistrer}>
            {erreurEdition && <div className="banner banner-error">{erreurEdition}</div>}

            {champs.map((c) => (
              <div className="field" key={c.nom}>
                <label htmlFor={`ref-${c.nom}`}>
                  {c.libelle}
                  {c.requis && ' *'}
                </label>
                {c.type === 'choix' ? (
                  <select
                    id={`ref-${c.nom}`}
                    className="select"
                    value={edition.valeurs[c.nom]}
                    onChange={(e) =>
                      setEdition((s) => ({ ...s, valeurs: { ...s.valeurs, [c.nom]: e.target.value } }))
                    }
                  >
                    {c.options.map((o) => (
                      <option key={o.valeur} value={o.valeur}>{o.libelle}</option>
                    ))}
                  </select>
                ) : c.type === 'choix-multiples' ? (
                  <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
                    {c.options.map((o) => (
                      <label key={o.valeur} style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 400 }}>
                        <input
                          type="checkbox"
                          checked={(edition.valeurs[c.nom] || []).includes(o.valeur)}
                          onChange={(e) =>
                            setEdition((s) => {
                              const actuel = s.valeurs[c.nom] || []
                              const suivant = e.target.checked
                                ? [...actuel, o.valeur]
                                : actuel.filter((x) => x !== o.valeur)
                              return { ...s, valeurs: { ...s.valeurs, [c.nom]: suivant } }
                            })
                          }
                        />
                        {o.libelle}
                      </label>
                    ))}
                  </div>
                ) : c.type === 'bool' ? (
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 400 }}>
                    <input
                      id={`ref-${c.nom}`}
                      type="checkbox"
                      checked={!!edition.valeurs[c.nom]}
                      onChange={(e) =>
                        setEdition((s) => ({ ...s, valeurs: { ...s.valeurs, [c.nom]: e.target.checked } }))
                      }
                    />
                    {c.libelleCase || c.libelle}
                  </label>
                ) : c.type === 'texte-long' ? (
                  // AJOUTE POUR ED-10 (blog de l'editeur). Un chapo et un corps d'article ne
                  // tiennent pas sur une ligne : dans un `<input>`, on ecrit a l'aveugle, sans voir
                  // ce qu'on a deja tape ni pouvoir aller a la ligne. Le composant sert douze
                  // referentiels — le type est donc ajoute ici plutot que dans un ecran, pour que
                  // le prochain qui en a besoin le trouve.
                  <textarea
                    id={`ref-${c.nom}`}
                    className="input"
                    rows={c.lignes || 6}
                    required={c.requis}
                    value={edition.valeurs[c.nom]}
                    placeholder={c.exemple}
                    onChange={(e) =>
                      setEdition((s) => ({ ...s, valeurs: { ...s.valeurs, [c.nom]: e.target.value } }))
                    }
                  />
                ) : (
                  <input
                    id={`ref-${c.nom}`}
                    className="input"
                    type={c.type === 'nombre' ? 'number' : c.type === 'date' ? 'date' : 'text'}
                    step={c.pas}
                    required={c.requis}
                    value={edition.valeurs[c.nom]}
                    placeholder={c.exemple}
                    onChange={(e) =>
                      setEdition((s) => ({ ...s, valeurs: { ...s.valeurs, [c.nom]: e.target.value } }))
                    }
                  />
                )}
                {/* La conséquence, pas la définition : ce qui change pour le client ou pour la
                    caisse quand on remplit ce champ. */}
                {c.aide && <div className="hint">{c.aide}</div>}
              </div>
            ))}

            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
              <button className="btn" type="button" onClick={() => setEdition(null)}>Annuler</button>
              <button className="btn primary" type="submit" disabled={enCours}>
                {enCours ? 'Enregistrement…' : 'Enregistrer'}
              </button>
            </div>
          </form>
        )}
      </Modal>
    </section>
  )
}
