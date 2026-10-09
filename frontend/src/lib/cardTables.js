/**
 * Below 640px style.css lays every `.data-table` row out as a card, and a card
 * field is meaningless without its column's name beside it. Copying each
 * header's text onto its column's cells here — rather than a `data-label` in
 * every view's template — leaves the tables untouched and labels a future one
 * for free. It re-runs on any DOM change, so new rows, a new page and a locale
 * switch (which rewrites the headers) all relabel.
 *
 * A cell spanning several columns (an expanded detail row) gets no label and
 * fills the card. Only attributes are written, and the observer ignores
 * attributes, so labelling never re-triggers itself.
 */

let scheduled = false

function label() {
  scheduled = false
  for (const table of document.querySelectorAll('table.data-table')) {
    const heads = [...(table.tHead?.rows[0]?.cells ?? [])].map((th) => th.textContent.trim())
    for (const body of table.tBodies) {
      for (const row of body.rows) {
        let column = 0
        for (const cell of row.cells) {
          const text = cell.colSpan === 1 ? (heads[column] ?? '') : ''
          if (cell.dataset.label !== text) cell.dataset.label = text
          column += cell.colSpan
        }
      }
    }
  }
}

new MutationObserver(() => {
  if (scheduled) return
  scheduled = true
  requestAnimationFrame(label)
}).observe(document.body, { childList: true, subtree: true, characterData: true })
