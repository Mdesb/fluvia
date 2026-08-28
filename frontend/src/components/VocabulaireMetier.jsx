import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { setVocabulaireLocal } from '../api/vocabulaire.js'

/**
 * LES MOTS DU MÉTIER — « praticien » chez le coiffeur, « ligne d'eau » à la piscine.
 *
 * **Ce que cet écran remplace, et qui aurait été une erreur.** Maxime demandait « les verticales salon
 * de massage, salon de coiffure ». La tentation était d'écrire un module. En regardant, le métier
 * était déjà là — une prestation avec sa durée, un praticien avec sa capacité, des horaires, des
 * congés, une politique d'annulation, une facturation des non-présentations. **Ce qui manquait,
 * c'étaient les mots.**
 *
 * **Et ils ne peuvent pas être globaux.** Le même code `ressource` désigne un praticien, une ligne
 * d'eau ou un court. Traduire une fois pour tout le monde rendrait le logiciel faux partout sauf à un
 * endroit.
 *
 * **Un champ vide ne remplace rien.** Il ne vide pas le mot : il rend simplement le terme par défaut.
 * L'inverse — accepter le vide comme un remplacement — afficherait des blancs à la place de termes, et
 * personne ne saurait d'où ils viennent.
 *
 * > **Une personnalisation qui peut produire une absence n'est pas une personnalisation : c'est une
 * > façon de casser l'écran sans s'en apercevoir.**
 */

// Les termes qui valent la peine d'être renommés, et eux seuls.
//
// Offrir les deux cents codes du vocabulaire produirait un écran que personne n'ouvre. Ceux-ci sont
// les mots qu'un exploitant PRONONCE tous les jours et qui changent d'un métier à l'autre.
const TERMES = [
  ['ressource', 'Ressource', 'praticien, ligne d’eau, court, salle…'],
  ['activite', 'Activité', 'prestation, cours, séance…'],
  ['creneau', 'Créneau', 'rendez-vous, séance, plage…'],
  ['beneficiaire', 'Bénéficiaire', 'client, adhérent, élève…'],
  ['reservation', 'Réservation', 'rendez-vous, inscription…'],
  ['support', 'Support', 'carte, bracelet, badge…'],
]

export default function VocabulaireMetier({ etabActif, peutEcrire }) {
  const [etablissement, setEtablissement] = useState(null)
  const [valeurs, setValeurs] = useState({})
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [busy, setBusy] = useState(false)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const liste = membres(await api.etablissements())
      const e = liste.find((x) => x.id === etabActif) || null
      setEtablissement(e)
      setValeurs(e?.vocabulaire || {})
    } catch (e) {
      setErreur(e.message || 'L’établissement n’a pas pu être lu.')
    } finally {
      setChargement(false)
    }
  }, [etabActif])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function enregistrer() {
    setBusy(true)
    setErreur(null)
    try {
      // Les champs vides ne partent pas : le serveur les écarterait de toute façon, et les envoyer
      // ferait croire qu'ils veulent dire quelque chose.
      const corps = {}
      for (const [code] of TERMES) {
        const v = (valeurs[code] || '').trim()
        if (v !== '') corps[code] = v
      }
      const maj = await api.majEtablissement(etablissement.id, {
        vocabulaire: Object.keys(corps).length > 0 ? corps : null,
      })
      // On applique IMMEDIATEMENT : sans ça, l'exploitant enregistre, ne voit rien changer, et
      // recommence — ou conclut que le réglage ne marche pas.
      setVocabulaireLocal(maj?.vocabulaire ?? corps)
      setSucces('Vocabulaire enregistré. Il s’applique dès maintenant.')
      await recharger()
    } catch (e) {
      setErreur(e.message || 'L’enregistrement a échoué.')
    } finally {
      setBusy(false)
    }
  }

  if (chargement) return <div className="center" style={{ minHeight: 100 }}><div className="spinner" /></div>

  return (
    <section className="card">
      <div className="card-h">
        <h3>Vocabulaire du métier</h3>
        <span className="sub">{etablissement?.nom || '—'}</span>
      </div>
      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {succes && <div className="banner banner-ok">{succes}</div>}

        <div className="hint" style={{ marginTop: 0 }}>
          Ces mots ne changent que pour cet établissement. Laissé vide, un terme garde son nom par
          défaut.
        </div>

        <div style={{ display: 'grid', gap: 12, marginTop: 12 }}>
          {TERMES.map(([code, defaut, exemples]) => (
            <div key={code} style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
              <span style={{ minWidth: 120 }} className="sub">{defaut}</span>
              <span className="sub">→</span>
              <input
                className="input sm"
                style={{ width: 220 }}
                value={valeurs[code] || ''}
                disabled={!peutEcrire}
                placeholder={defaut}
                onChange={(e) => setValeurs((v) => ({ ...v, [code]: e.target.value }))}
              />
              <span className="sub" style={{ fontSize: 12 }}>{exemples}</span>
            </div>
          ))}
        </div>

        {peutEcrire && (
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14 }}>
            <button className="btn primary" type="button" disabled={busy || !etablissement} onClick={enregistrer}>
              Enregistrer
            </button>
          </div>
        )}
      </div>
    </section>
  )
}
