# Smoke Test FP Image Optimizer

Checklist per verificare tutti i flussi del plugin sul sito fp-development.local.

## Prerequisiti

- **Plugin attivo** (da Plugin → Attiva FP Image Optimizer)
- `vendor/` presente (composer install)
- Utente con `manage_options`

> **Nota**: Se la pagina Impostazioni → FP Image Optimizer mostra "WordPress › Errore", il plugin è probabilmente disattivato.

---

## 1. Pagina Impostazioni

| Step | Azione | Verifica |
|------|--------|----------|
| 1.1 | Impostazioni → FP Image Optimizer | Pagina si carica, header "FP Image Optimizer", badge versione |
| 1.2 | Verifica status WebP/AVIF | Pill verde "WebP supportato" e/o "AVIF supportato" (o rosso se mancante) |
| 1.3 | Abilita ottimizzazione | Toggle ON, badge "Attivo" |
| 1.4 | Salva impostazioni | Messaggio "Impostazioni salvate" |
| 1.5 | Toggle Rinomina all'upload | Attiva, salva |
| 1.6 | Toggle Duplicato al salvataggio | Attiva, salva |
| 1.7 | Toggle Attributi SEO | Attiva, salva |

**PASS** se la pagina si carica senza errori e i toggle rispondono.

---

## 2. Upload immagine (conversione + rename)

| Step | Azione | Verifica |
|------|--------|----------|
| 2.1 | Media → Aggiungi file media | Uploader si apre |
| 2.2 | Carica un JPG/PNG (es. 500x500px) | Upload completa |
| 2.3 | Nella cartella uploads | File .webp e .avif accanto all'originale |
| 2.4 | Se rename attivo | Nome file = `sitename-slug-id.jpg` |
| 2.5 | Dettaglio attachment | Alt/title/caption compilati (se SEO attivo) |

**PASS** se conversione e (opzionale) rename funzionano.

---

## 3. Azione "Converti in WebP/AVIF" (Media Library)

| Step | Azione | Verifica |
|------|--------|----------|
| 3.1 | Media → Libreria | Lista immagini |
| 3.2 | Hover su immagine JPG/PNG | Link "Converti in WebP/AVIF" visibile |
| 3.3 | Click su "Converti in WebP/AVIF" | Redirect/aggiornamento, nessun errore |
| 3.4 | Verifica cartella uploads | File .webp e .avif creati |

**PASS** se l'azione converte senza errori.

---

## 4. Duplicato al salvataggio + SEO

| Step | Azione | Verifica |
|------|--------|----------|
| 4.1 | Crea/Modifica articolo o pagina | Editor si apre |
| 4.2 | Inserisci immagine esistente nel contenuto | Immagine visibile nel blocco |
| 4.3 | Salva articolo/pagina | Salvataggio OK |
| 4.4 | Controlla cartella uploads | Nuova copia con nome `sito-slug-id.ext` |
| 4.5 | Controlla attachment (se SEO attivo) | Alt/title/caption compilati |

**PASS** se al salvataggio vengono create copie contestuali.

---

## 5. Frontend – Picture / WebP

| Step | Azione | Verifica |
|------|--------|----------|
| 5.1 | Apri pagina/articolo con immagine nel browser | Pagina si carica |
| 5.2 | Ispeziona elemento (DevTools) | Tag `<picture>` con `<source type="image/webp">` e `<source type="image/avif">` |
| 5.3 | `<img>` ha `src` originale | Fallback funziona |
| 5.4 | Network tab | Richieste .webp o .avif (se browser supporta) |

**PASS** se il frontend usa `<picture>` e le varianti.

---

## 6. Thumbnail / Featured Image

| Step | Azione | Verifica |
|------|--------|----------|
| 6.1 | Articolo con immagine in evidenza | Apri frontend |
| 6.2 | Ispeziona thumbnail | Wrappata in `<picture>` con varianti |

**PASS** se le thumbnail usano picture.

---

## Riepilogo

- [ ] 1. Impostazioni
- [ ] 2. Upload + conversione
- [ ] 3. Converti (Media Library)
- [ ] 4. Duplicato al salvataggio
- [ ] 5. Frontend picture
- [ ] 6. Thumbnail

**Tutti PASS** = smoke test OK.
