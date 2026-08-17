import { useEffect, useState } from 'react'
import QRCode from 'qrcode'

// QR réel (lib `qrcode`) rendu en image data-URL — se dimensionne exactement dans sa boîte.
// Encode `value` (code de support signé). Fond blanc / modules sombres pour rester scannable
// quel que soit le thème.
export default function Qr({ value, size = 96, title = 'QR billet' }) {
  const [src, setSrc] = useState('')
  const [erreur, setErreur] = useState(false)

  useEffect(() => {
    let annule = false
    if (!value) {
      setSrc('')
      return
    }
    QRCode.toDataURL(String(value), {
      errorCorrectionLevel: 'M',
      margin: 1,
      width: 256,
      color: { dark: '#101010', light: '#ffffff' },
    })
      .then((url) => {
        if (!annule) {
          setErreur(false)
          setSrc(url)
        }
      })
      .catch(() => {
        if (!annule) setErreur(true)
      })
    return () => {
      annule = true
    }
  }, [value])

  if (!value || erreur || !src) {
    // Repli : pas de code exploitable / génération impossible -> pavé neutre (jamais d'écran cassé).
    return <div className="qr" style={{ width: size, height: size }} title={title} />
  }

  return (
    <img
      src={src}
      alt={title}
      title={title}
      width={size}
      height={size}
      style={{ width: size, height: size, borderRadius: 8, background: '#fff', flex: 'none', display: 'block' }}
    />
  )
}
