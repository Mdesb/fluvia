import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'

/**
 * LES CONTACTS D'UN CLIENT PROFESSIONNEL — avec leur fonction.
 *
 * **Ce que la fiche ne savait pas montrer.** Un client moral avait une raison sociale, un SIRET, un
 * courriel et un téléphone — **un seul de chaque**. Une entreprise n'est pas une personne : c'est une
 * directrice, une comptabilité, quelqu'un qui signe, et ils n'ont pas la même adresse.
 *
 * > **Se tromper d'interlocuteur coûte une facture impayée.**
 *
 * **Le bloc n'apparaît que pour les clients moraux.** Ajouter « contacts » sur la fiche d'un
 * particulier créerait une notion vide à remplir : le particulier *est* son propre contact, et
 * `Beneficiaire` couvre déjà les personnes qui l'accompagnent.
 *
 * ⚠ **La liste est chargée entière puis filtrée ici.** `CustomerContact` porte un `SearchFilter` sur
 * `customer` — famille D58, où le filtre rend soit tout (paramètre ignoré) soit rien (identifiant lié
 * sans type), sans jamais lever. On ne l'emprunte pas.
 */
export default function ContactsClient({ client, peutModifier }) {
  const [contacts, setContacts] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [busy, setBusy] = useState(false)
  const [ajout, setAjout] = useState(false)

  const recharger = useCallback(async () => {
    if (!client?.id) return
    setChargement(true)
    try {
      setContacts(membres(await api.contactsClient(client.id)))
    } catch (e) {
      setErreur(e.message || 'Les contacts n’ont pas pu être chargés.')
    } finally {
      setChargement(false)
    }
  }, [client?.id])

  useEffect(() => {
    recharger()
  }, [recharger])

  async function agir(action) {
    setBusy(true)
    setErreur(null)
    try {
      await action()
      await recharger()
    } catch (e) {
      // Le message du serveur est conservé : sur une violation d'unicité, c'est lui qui dit qu'un
      // contact principal existe déjà. « Échec » ferait réessayer à l'identique.
      setErreur(e.message || 'L’action a échoué.')
    } finally {
      setBusy(false)
    }
  }

  if (client?.type !== 'morale') return null

  return (
    <div>
      <div className="fiche-sec">Contacts</div>
      {erreur && <div className="banner banner-error">{erreur}</div>}

      {chargement ? (
        <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
      ) : contacts.length === 0 ? (
        <div className="sub" style={{ padding: '6px 0' }}>
          Aucun contact nommé. Une société sans interlocuteur identifié est une société qu&rsquo;on ne
          sait plus joindre le jour où la personne change.
        </div>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Nom</th>
                <th>Fonction</th>
                <th>Courriel</th>
                <th>Téléphone</th>
                {peutModifier && <th />}
              </tr>
            </thead>
            <tbody>
              {[...contacts]
                // Le principal en tête : c'est celui à qui on écrit par défaut.
                .sort((a, b) => Number(b.primaryContact) - Number(a.primaryContact))
                .map((c) => (
                  <tr key={c.id}>
                    <td>
                      <span className="nm">{c.displayName || c.lastName}</span>
                      {c.primaryContact && <span className="badge good" style={{ marginLeft: 6 }}>principal</span>}
                      {c.note && <div className="sub">{c.note}</div>}
                    </td>
                    <td>{c.jobTitle || <span className="sub">—</span>}</td>
                    <td>{c.email || <span className="sub">—</span>}</td>
                    <td>{c.phone || <span className="sub">—</span>}</td>
                    {peutModifier && (
                      <td>
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                          {!c.primaryContact && (
                            <button
                              className="btn ghost sm"
                              type="button"
                              disabled={busy}
                              onClick={() =>
                                agir(async () => {
                                  // ON RETIRE L'ANCIEN PRINCIPAL AVANT DE POSER LE NOUVEAU.
                                  //
                                  // La base n'accepte qu'un principal par client (index unique).
                                  // L'ordre inverse heurterait la contrainte et rendrait une erreur
                                  // technique la où l'utilisateur a fait un geste parfaitement sensé.
                                  const ancien = contacts.find((x) => x.primaryContact)
                                  if (ancien) await api.majContactClient(ancien.id, { primaryContact: false })
                                  await api.majContactClient(c.id, { primaryContact: true })
                                })
                              }
                            >
                              Rendre principal
                            </button>
                          )}
                          <button
                            className="btn ghost sm"
                            type="button"
                            disabled={busy}
                            onClick={() => agir(() => api.supprimerContactClient(c.id))}
                          >
                            Retirer
                          </button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      )}

      {peutModifier && (
        ajout ? (
          <FormulaireContact
            busy={busy}
            premier={contacts.length === 0}
            onAnnuler={() => setAjout(false)}
            onCreer={(corps) =>
              agir(async () => {
                await api.creerContactClient({ ...corps, customer: `/api/clients/${client.id}` })
                setAjout(false)
              })
            }
          />
        ) : (
          <button className="btn ghost sm" type="button" style={{ marginTop: 8 }} onClick={() => setAjout(true)}>
            + Ajouter un contact
          </button>
        )
      )}
    </div>
  )
}

function FormulaireContact({ busy, premier, onAnnuler, onCreer }) {
  const [nom, setNom] = useState('')
  const [prenom, setPrenom] = useState('')
  const [fonction, setFonction] = useState('')
  const [email, setEmail] = useState('')
  const [tel, setTel] = useState('')
  // Le premier contact d'une société est principal par défaut : ne pas le proposer obligerait à
  // cocher une case dont l'utilisateur ne voit pas encore l'intérêt, et personne ne serait principal.
  const [principal, setPrincipal] = useState(premier)

  return (
    <div style={{ display: 'grid', gap: 10, marginTop: 10, padding: 12, border: '1px solid var(--line)', borderRadius: 8 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 10 }}>
        <div>
          <label htmlFor="ct-nom">Nom *</label>
          <input id="ct-nom" className="input" value={nom} onChange={(e) => setNom(e.target.value)} />
        </div>
        <div>
          <label htmlFor="ct-prenom">Prénom</label>
          <input id="ct-prenom" className="input" value={prenom} onChange={(e) => setPrenom(e.target.value)} />
        </div>
        <div>
          <label htmlFor="ct-fonction">Fonction</label>
          <input
            id="ct-fonction"
            className="input"
            value={fonction}
            onChange={(e) => setFonction(e.target.value)}
            placeholder="Ex. Responsable des sorties scolaires"
          />
        </div>
        <div>
          <label htmlFor="ct-email">Courriel</label>
          <input id="ct-email" className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div>
          <label htmlFor="ct-tel">Téléphone</label>
          <input id="ct-tel" className="input" value={tel} onChange={(e) => setTel(e.target.value)} />
        </div>
      </div>

      <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13 }}>
        <input type="checkbox" checked={principal} onChange={(e) => setPrincipal(e.target.checked)} />
        Interlocuteur principal
      </label>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
        <button className="btn ghost" type="button" onClick={onAnnuler}>Annuler</button>
        <button
          className="btn primary"
          type="button"
          disabled={busy || nom.trim() === ''}
          onClick={() =>
            onCreer({
              lastName: nom.trim(),
              firstName: prenom.trim() || null,
              jobTitle: fonction.trim() || null,
              email: email.trim() || null,
              phone: tel.trim() || null,
              primaryContact: principal,
            })
          }
        >
          Ajouter
        </button>
      </div>
    </div>
  )
}
