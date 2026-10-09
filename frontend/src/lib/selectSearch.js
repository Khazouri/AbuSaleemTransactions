import i18n from '../i18n'

/**
 * Every single-choice <select> in the SPA opens a searchable list instead of
 * the browser's own. Done here, once, rather than as a component swapped into
 * forty-odd templates: the native <select> stays in the DOM as the one source
 * of truth, so `v-model` (number values included), `@change` handlers, form
 * layout and the options Vue keeps rendering into it all work unchanged, and a
 * select added later is searchable for free.
 *
 * The options are read from the select each time the list opens or the query
 * changes, never cached, so a list Vue has since re-rendered is never stale.
 * Picking an option selects it on the real element and fires the same
 * `input` + `change` pair the browser would, and only when the value changed.
 */

const t = (key) => i18n.global.t(key)

// Arabic users type an alef without its hamza, and ة/ه, ى/ي interchangeably.
// Each fold is one character to one character, so a match's index in the
// folded label is its index in the real label — the highlight relies on that.
const FOLDS = { أ: 'ا', إ: 'ا', آ: 'ا', ة: 'ه', ى: 'ي' }
const fold = (text) => text.toLowerCase().replace(/[أإآةى]/g, (c) => FOLDS[c])

const searchable = (el) => el instanceof HTMLSelectElement && !el.disabled && !el.multiple && el.size <= 1

let current = null
let opened = 0

function open(select, query = '') {
  close(false)
  const listId = `select-search-${++opened}`
  const popover = document.createElement('div')
  popover.className = 'select-search'

  const input = document.createElement('input')
  input.className = 'select-search-input'
  input.type = 'search'
  input.autocomplete = 'off'
  input.value = query
  input.placeholder = t('selectSearch.placeholder')
  input.setAttribute('role', 'combobox')
  input.setAttribute('aria-expanded', 'true')
  input.setAttribute('aria-autocomplete', 'list')
  input.setAttribute('aria-controls', listId)
  input.setAttribute('aria-label', select.labels?.[0]?.textContent.trim() || input.placeholder)

  const list = document.createElement('ul')
  list.className = 'select-search-list'
  list.id = listId
  list.setAttribute('role', 'listbox')

  popover.append(input, list)
  document.body.append(popover)
  current = { select, popover, input, list, listId, items: [], active: -1 }

  input.addEventListener('input', render)
  input.addEventListener('keydown', onInputKeydown)
  // Keeps focus in the search box when an option or the padding is pressed.
  popover.addEventListener('mousedown', (event) => event.preventDefault())
  list.addEventListener('click', (event) => {
    const li = event.target.closest('[role="option"]')
    if (li) choose(current.items.findIndex((item) => item.li === li))
  })
  list.addEventListener('mousemove', (event) => {
    const li = event.target.closest('[role="option"]')
    const index = li ? current.items.findIndex((item) => item.li === li) : -1
    if (index !== -1 && index !== current.active) setActive(index, false)
  })

  render()
  place()
  input.focus()
}

function render() {
  const { select, input, list, listId } = current
  const query = fold(input.value.trim())
  list.replaceChildren()
  current.items = []
  let group = null

  for (const option of select.options) {
    const label = option.label
    const at = query ? fold(label).indexOf(query) : -1
    if (query && at === -1) continue

    const parent = option.parentElement instanceof HTMLOptGroupElement ? option.parentElement : null
    if (parent && parent !== group) {
      const heading = document.createElement('li')
      heading.className = 'select-search-group'
      heading.setAttribute('role', 'presentation')
      heading.textContent = parent.label
      list.append(heading)
    }
    group = parent

    const li = document.createElement('li')
    li.id = `${listId}-${current.items.length}`
    li.setAttribute('role', 'option')
    li.setAttribute('aria-selected', String(option.selected))
    const disabled = option.disabled || Boolean(parent?.disabled)
    if (disabled) li.setAttribute('aria-disabled', 'true')
    if (at === -1) {
      li.textContent = label
    } else {
      const mark = document.createElement('mark')
      mark.textContent = label.slice(at, at + query.length)
      li.append(label.slice(0, at), mark, label.slice(at + query.length))
    }
    list.append(li)
    current.items.push({ li, option, disabled })
  }

  if (!current.items.length) {
    const empty = document.createElement('li')
    empty.className = 'select-search-empty'
    empty.setAttribute('role', 'presentation')
    empty.textContent = t('selectSearch.empty')
    list.append(empty)
  }

  const selected = query ? -1 : current.items.findIndex((item) => item.option.selected)
  setActive(selected !== -1 ? selected : step(-1, 1))
}

// The next enabled item from `from` in direction `delta`, or `from` itself at either end.
function step(from, delta) {
  for (let i = from + delta; i >= 0 && i < current.items.length; i += delta) {
    if (!current.items[i].disabled) return i
  }
  return from
}

function setActive(index, scroll = true) {
  current.items[current.active]?.li.classList.remove('is-active')
  current.active = index
  const item = current.items[index]
  if (!item) return current.input.removeAttribute('aria-activedescendant')
  item.li.classList.add('is-active')
  current.input.setAttribute('aria-activedescendant', item.li.id)
  if (scroll) item.li.scrollIntoView({ block: 'nearest' })
}

function choose(index) {
  const item = current?.items[index]
  if (!item || item.disabled) return
  const { select } = current
  const changed = !item.option.selected
  item.option.selected = true
  close()
  if (!changed) return
  select.dispatchEvent(new Event('input', { bubbles: true }))
  select.dispatchEvent(new Event('change', { bubbles: true }))
}

function close(refocus = true) {
  if (!current) return
  const { select, popover } = current
  current = null
  popover.remove()
  if (refocus && select.isConnected) select.focus()
}

// Under the select, or over it when there is more room above. Physical
// left/top here are measured geometry, not layout CSS, so RTL needs only the
// edge choice: the list lines up with the select's inline-start edge.
function place() {
  const { select, popover } = current
  if (!select.isConnected) return close(false)
  const box = select.getBoundingClientRect()
  const viewportWidth = document.documentElement.clientWidth
  const width = Math.min(Math.max(box.width, 224), viewportWidth - 16)
  const rtl = getComputedStyle(select).direction === 'rtl'
  const left = Math.min(Math.max(rtl ? box.right - width : box.left, 8), viewportWidth - width - 8)
  const below = window.innerHeight - box.bottom - 12
  const above = box.top - 12
  const upward = below < 240 && above > below

  popover.style.left = `${left}px`
  popover.style.width = `${width}px`
  popover.style.maxHeight = `${Math.min(upward ? above : below, 352)}px`
  popover.style.top = upward ? 'auto' : `${box.bottom + 4}px`
  popover.style.bottom = upward ? `${window.innerHeight - box.top + 4}px` : 'auto'
}

function onInputKeydown(event) {
  const keys = {
    ArrowDown: () => setActive(step(current.active, 1)),
    ArrowUp: () => setActive(step(current.active, -1)),
    Home: () => setActive(step(-1, 1)),
    End: () => setActive(step(current.items.length, -1)),
    Enter: () => choose(current.active),
    Escape: () => close(),
  }
  if (event.key === 'Tab') return close()
  if (!keys[event.key]) return
  event.preventDefault()
  // An AppModal underneath closes on Escape from document; this one is ours.
  event.stopPropagation()
  keys[event.key]()
}

document.addEventListener('mousedown', (event) => {
  if (current?.popover.contains(event.target)) return
  if (!searchable(event.target)) return close(false)
  // Suppresses the browser's own list (and its focus, so focus is given back by hand).
  event.preventDefault()
  if (current?.select === event.target) return close()
  event.target.focus()
  open(event.target)
}, true)

document.addEventListener('keydown', (event) => {
  if (current || !searchable(event.target)) return
  const opens = ['Enter', ' ', 'F4'].includes(event.key)
    || (event.altKey && ['ArrowDown', 'ArrowUp'].includes(event.key))
  // A typed letter starts the search with it, where the native list would jump to it.
  const typed = event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey
  if (!opens && !typed) return
  event.preventDefault()
  open(event.target, opens ? '' : event.key)
}, true)

// A scrolling page or modal carries the select with it; the list follows.
window.addEventListener('scroll', (event) => {
  if (current && !current.popover.contains(event.target)) place()
}, true)
window.addEventListener('resize', () => current && place())
