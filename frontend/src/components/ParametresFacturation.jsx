import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'

// LE PARAMÉTRAGE DE FACTURATION — une ressource complète que RIEN n'appelait.
//
// ⚠ CE N'ÉTAIT PAS UN CONFORT MANQUANT, ET DEUX MANQUES SIGNALÉS AILLEURS VIENNENT DE LÀ.
//
//   — `tauxPenaliteRetard` est nullable et personne ne pouvait le renseigner. Le taux de pénalités
//     « absent des factures » n'était pas absent du modèle : il était inatteignable ;
//   — `ResolveurComptesFacturation` dit, quand il échoue : « renseignez une catégorie comptable
//     mappée, OU un compte de produit par défaut dans le paramétrage de facturation ». La seconde
//     voie n'existait pas. Un message d'erreur qui propose deux sorties dont une est fermée envoie
//     chercher là où il n'y a rien.
//
// ⚠ CE QUE CET ÉCRAN NE PRÉTEND PAS ÊTRE. La première voie de ce message — les correspondances
// comptables — est atteignable, elle. Ce paramétrage arme un REPLI, il ne débloque pas une
// facturation impossible. Le dire autrement le vendrait plus rouge qu'il n'est.
//
// ── UN PARAMÉTRAGE PAR PROFIL COMPTABLE, ET C'EST LE SERVEUR QUI LE SAIT ────────────────────────
//
// La ressource porte `profilExploitant`. On ne le choisit pas ici : on lit ce qui existe, et on
// crée s'il n'y a rien. Proposer un sélecteur de profil sur un écran de réglages ferait porter à
// l'exploitant une question dont il n'a pas les éléments — et permettrait de régler le voisin.

const CHAMPS_TEXTE = [
  {
    nom: 'conditionsReglementDefaut',
    libelle: 'Conditions de règlement',
    aide: 'La phrase imprimée en bas de chaque facture. Elle vaut engagement contractuel.',
    exemple: 'Paiement à 30 jours date de facture.',
  },
  {
    nom: 'mentionTvaSpecifique',
    libelle: 'Mention de TVA particulière',
    aide: 'Par exemple « TVA non applicable, art. 293 B du CGI ». Laissez vide si vous facturez la TVA normalement.',
    exemple: '',
  },
]

export default function ParametresFacturation({ peutModifier }) {
  const [parametre, setParametre] = useState(null)
  const [valeurs, setValeurs] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [info, setInfo] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [nonBranche, setNonBranche] = useState(false)

  const charger = useCallback(async () => {
    setChargement(true)
    try {
      const r = await api.parametresFacturation()
      const liste = membres(r)
      const p = liste[0] || null
      setParametre(p)
      setValeurs(p ? depuis(p) : depuis({}))
      setNonBranche(false)
      setErreur(null)
    } catch (e) {
      if (e?.status === 404) setNonBranche(true)
      else setErreur(e?.message || 'Le paramétrage n’a pas pu être lu.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    charger()
  }, [charger])

  async function enregistrer(e) {
    e.preventDefault()
    setEnCours(true)
    setErreur(null)
    setInfo('')
    try {
      // ⚠ LES MONTANTS ET LES TAUX PARTENT EN CHAÎNE, JAMAIS EN NOMBRE.
      //
      // `tauxPenaliteRetard` et `indemniteForfaitaireRecouvrement` sont déclarés `?string` côté
      // serveur : un `Number()` rend un 422 « The type of the attribute must be string ». C'est le
      // défaut exact qui a rendu la création d'un taux de TVA impossible pendant des jours.
      // Et sur de l'argent, la virgule flottante binaire ne représente pas 40,10 exactement.
      const corps = {
        conditionsReglementDefaut: valeurs.conditionsReglementDefaut,
        delaiPaiementDefautJours: Number(valeurs.delaiPaiementDefautJours) || 0,
        tauxPenaliteRetard: valeurs.tauxPenaliteRetard === '' ? null : String(valeurs.tauxPenaliteRetard),
        indemniteForfaitaireRecouvrement: String(valeurs.indemniteForfaitaireRecouvrement),
        mentionTvaSpecifique: valeurs.mentionTvaSpecifique === '' ? null : valeurs.mentionTvaSpecifique,
        chorusProActif: !!valeurs.chorusProActif,
      }

      const enregistre = parametre?.id
        ? await api.majParametreFacturation(parametre.id, corps)
        : await api.creerParametreFacturation(corps)

      // ⚠ ON REFUSE D'ANNONCER UN ENREGISTREMENT QU'ON N'A PAS CONSTATÉ.
      //
      // Le serveur peut accepter la requête et ignorer un champ — groupe d'écriture absent, champ
      // renommé, mise à jour partielle. Un « Enregistré » posé sur un 200 dirait alors le contraire
      // de ce qui s'est passé, et l'exploitant repartirait en croyant son taux de pénalités posé.
      const retenu = String(enregistre?.tauxPenaliteRetard ?? '')
      const voulu = String(corps.tauxPenaliteRetard ?? '')
      if (retenu !== voulu) {
        setErreur(
          `Le serveur a accepté la demande mais a retenu « ${retenu || 'rien'} » comme taux de `
          + `pénalités, là où vous aviez saisi « ${voulu || 'rien'} ». Ne considérez pas ce réglage `
          + 'comme enregistré.',
        )
      } else {
        setInfo('Paramétrage enregistré.')
      }

      setParametre(enregistre)
      setValeurs(depuis(enregistre))
    } catch (err) {
      setErreur(err.message || 'L’enregistrement n’a pas abouti.')
    } finally {
      setEnCours(false)
    }
  }

  if (nonBranche) {
    return (
      <div className="hint" style={{ margin: 0 }}>
        Le paramétrage de facturation n’est pas ouvert par ce serveur.
      </div>
    )
  }

  if (chargement || !valeurs) {
    return <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
  }

  return (
    <form className="card" onSubmit={enregistrer} style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Paramétrage de facturation</h3>
        <span className="sub">
          Ce qui s’applique par défaut à vos factures : délais, pénalités, mentions.
        </span>
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {info && <div className="banner banner-ok">{info}</div>}

        {!parametre && (
          <div className="hint" style={{ marginBottom: 'var(--esp-large)' }}>
            Aucun paramétrage n’existe encore. Les valeurs ci-dessous sont celles que le serveur
            appliquera par défaut ; enregistrez pour les fixer.
          </div>
        )}

        {CHAMPS_TEXTE.map((c) => (
          <div className="field" key={c.nom}>
            <label htmlFor={`pf-${c.nom}`}>{c.libelle}</label>
            <input
              id={`pf-${c.nom}`}
              className="input"
              type="text"
              value={valeurs[c.nom] ?? ''}
              placeholder={c.exemple}
              disabled={!peutModifier}
              onChange={(e) => setValeurs((v) => ({ ...v, [c.nom]: e.target.value }))}
            />
            <span className="hint">{c.aide}</span>
          </div>
        ))}

        <div className="field">
          <label htmlFor="pf-delai">Délai de paiement (jours)</label>
          <input
            id="pf-delai"
            className="input"
            type="number"
            min="0"
            value={valeurs.delaiPaiementDefautJours}
            disabled={!peutModifier}
            onChange={(e) => setValeurs((v) => ({ ...v, delaiPaiementDefautJours: e.target.value }))}
          />
        </div>

        <div className="field">
          <label htmlFor="pf-penalite">Taux de pénalités de retard (%)</label>
          <input
            id="pf-penalite"
            className="input"
            type="text"
            inputMode="decimal"
            placeholder="10.00"
            value={valeurs.tauxPenaliteRetard ?? ''}
            disabled={!peutModifier}
            onChange={(e) => setValeurs((v) => ({ ...v, tauxPenaliteRetard: e.target.value }))}
          />
          <span className="hint">
            Laissé vide, aucune pénalité n’est mentionnée sur vos factures. La loi en impose la
            mention entre professionnels.
          </span>
        </div>

        <div className="field">
          <label htmlFor="pf-indemnite">Indemnité forfaitaire de recouvrement (€)</label>
          <input
            id="pf-indemnite"
            className="input"
            type="text"
            inputMode="decimal"
            value={valeurs.indemniteForfaitaireRecouvrement}
            disabled={!peutModifier}
            onChange={(e) => setValeurs((v) => ({ ...v, indemniteForfaitaireRecouvrement: e.target.value }))}
          />
          <span className="hint">40 € entre professionnels, sauf disposition contraire.</span>
        </div>

        <div className="field">
          <label htmlFor="pf-chorus" className="r" style={{ gap: 'var(--esp-normal)' }}>
            <input
              id="pf-chorus"
              type="checkbox"
              checked={!!valeurs.chorusProActif}
              disabled={!peutModifier}
              onChange={(e) => setValeurs((v) => ({ ...v, chorusProActif: e.target.checked }))}
            />
            Facturation Chorus Pro (secteur public)
          </label>
        </div>

        {peutModifier && (
          <button className="btn primary" type="submit" disabled={enCours}>
            {enCours ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        )}
      </div>
    </form>
  )
}

function depuis(p) {
  return {
    conditionsReglementDefaut: p?.conditionsReglementDefaut ?? 'Paiement à 30 jours date de facture.',
    delaiPaiementDefautJours: p?.delaiPaiementDefautJours ?? 30,
    tauxPenaliteRetard: p?.tauxPenaliteRetard ?? '',
    indemniteForfaitaireRecouvrement: p?.indemniteForfaitaireRecouvrement ?? '40.00',
    mentionTvaSpecifique: p?.mentionTvaSpecifique ?? '',
    chorusProActif: !!p?.chorusProActif,
  }
}
