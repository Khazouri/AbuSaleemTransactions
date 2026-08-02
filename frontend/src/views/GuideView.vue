<script setup>
/**
 * User guide (دليل الاستخدام) — Stage 27.
 *
 * A searchable, category-grouped list of help articles beside a reading pane.
 * Everyone reads; R08 edits in place through the same screen, which is what
 * the `user_guide` grants (view/print to all, add/edit/delete to R08) have
 * described since the screen was first seeded.
 *
 * Article bodies are rendered as plain-text paragraphs and never with v-html.
 * The content is editable through the API, so treating it as HTML would turn
 * the help screen into a stored-XSS surface aimed at every reader in the
 * system — the one place in this app where every role lands.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()

const articles = ref([])
const loading = ref(false)
const loadError = ref(null)
const search = ref('')
const selectedId = ref(null)

const editingId = ref(null)
const creating = ref(false)
const form = ref(blankForm())
const errors = ref({})
const formError = ref(null)
const saving = ref(false)

function blankForm() {
  return {
    code: '', category: '',
    title_ar: '', title_en: '',
    body_ar: '', body_en: '',
    sort_order: 0, is_active: true,
  }
}

function title(article) {
  if (!article) return ''
  return locale.value === 'ar'
    ? article.title_ar || article.title_en
    : article.title_en || article.title_ar
}

function body(article) {
  if (!article) return ''
  return locale.value === 'ar'
    ? article.body_ar || article.body_en
    : article.body_en || article.body_ar
}

/** Blank lines separate paragraphs; everything else stays literal text. */
function paragraphs(article) {
  return (body(article) ?? '')
    .split(/\n\s*\n/)
    .map((block) => block.trim())
    .filter(Boolean)
}

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return articles.value
  return articles.value.filter((article) =>
    `${article.title_ar} ${article.title_en ?? ''} ${article.body_ar} ${article.body_en ?? ''}`
      .toLowerCase()
      .includes(term),
  )
})

/** Grouped for the sidebar, preserving the server's category/sort ordering. */
const grouped = computed(() => {
  const groups = new Map()
  for (const article of filtered.value) {
    const key = article.category || t('guide.uncategorised')
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(article)
  }
  return [...groups.entries()].map(([category, items]) => ({ category, items }))
})

const selected = computed(() => articles.value.find((a) => a.id === selectedId.value) ?? null)

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/guide-articles')
    articles.value = data.data ?? []
    if (!articles.value.some((a) => a.id === selectedId.value)) {
      selectedId.value = articles.value[0]?.id ?? null
    }
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

function startCreate() {
  creating.value = true
  editingId.value = null
  form.value = blankForm()
  errors.value = {}
  formError.value = null
}

function startEdit(article) {
  creating.value = false
  editingId.value = article.id
  form.value = { ...article }
  errors.value = {}
  formError.value = null
}

function cancelForm() {
  creating.value = false
  editingId.value = null
  errors.value = {}
  formError.value = null
}

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = null

  const payload = { ...form.value }
  for (const key of ['title_en', 'body_en', 'category']) payload[key] = payload[key] || null

  try {
    if (editingId.value === null) {
      const { data } = await api.post('/guide-articles', payload)
      selectedId.value = data.data.id
    } else {
      await api.put(`/guide-articles/${editingId.value}`, payload)
    }
    cancelForm()
    await load()
  } catch (error) {
    if (error?.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
      formError.value = error.response.data.message ?? null
    } else {
      formError.value = t('guide.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

async function remove(article) {
  if (!window.confirm(t('common.confirmDelete'))) return
  try {
    await api.delete(`/guide-articles/${article.id}`)
    if (selectedId.value === article.id) selectedId.value = null
    await load()
  } catch (error) {
    formError.value = error?.response?.data?.message ?? t('guide.deleteFailed')
  }
}

/** `window` isn't in template scope, so the print grant needs a real handler. */
function printPage() { window.print() }

onMounted(load)
</script>

<template>
  <section class="guide">
    <div class="heading no-print">
      <div>
        <h2>{{ t('guide.title') }}</h2>
        <p class="subtitle">{{ t('guide.subtitle') }}</p>
      </div>
      <div class="heading-actions">
        <button v-can="'user_guide.print'" class="ghost" type="button" @click="printPage">
          {{ t('guide.print') }}
        </button>
        <button v-can="'user_guide.add'" class="primary" type="button" @click="startCreate">
          {{ t('guide.add') }}
        </button>
      </div>
    </div>

    <p v-if="loadError" class="alert no-print">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </p>
    <p v-if="formError" class="alert no-print">{{ formError }}</p>

    <!-- Editor: shown in place of the reading pane while adding or editing. -->
    <form v-if="creating || editingId !== null" class="card editor no-print" @submit.prevent="save">
      <h3>{{ editingId === null ? t('guide.add') : t('guide.edit') }}</h3>
      <div class="grid">
        <label>
          {{ t('guide.code') }}
          <input v-model="form.code" class="ltr" type="text" required />
          <small v-if="errors.code" class="field-error">{{ errors.code[0] }}</small>
          <small v-else class="hint">{{ t('guide.codeHint') }}</small>
        </label>
        <label>
          {{ t('guide.category') }}
          <input v-model="form.category" type="text" />
          <small v-if="errors.category" class="field-error">{{ errors.category[0] }}</small>
        </label>
        <label>
          {{ t('guide.sortOrder') }}
          <input v-model.number="form.sort_order" class="ltr" type="number" min="0" />
        </label>
      </div>
      <div class="grid">
        <label>
          {{ t('guide.titleAr') }}
          <input v-model="form.title_ar" type="text" required />
          <small v-if="errors.title_ar" class="field-error">{{ errors.title_ar[0] }}</small>
        </label>
        <label>
          {{ t('guide.titleEn') }}
          <input v-model="form.title_en" class="ltr" type="text" />
        </label>
      </div>
      <div class="grid bodies">
        <label>
          {{ t('guide.bodyAr') }}
          <textarea v-model="form.body_ar" rows="10" required />
          <small v-if="errors.body_ar" class="field-error">{{ errors.body_ar[0] }}</small>
          <small v-else class="hint">{{ t('guide.bodyHint') }}</small>
        </label>
        <label>
          {{ t('guide.bodyEn') }}
          <textarea v-model="form.body_en" class="ltr" rows="10" />
        </label>
      </div>
      <label class="checkbox">
        <input v-model="form.is_active" type="checkbox" />
        {{ t('guide.published') }}
      </label>
      <div class="actions">
        <button class="primary" type="submit" :disabled="saving">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button>
      </div>
    </form>

    <div v-else class="layout">
      <aside class="card index no-print">
        <input v-model="search" type="search" :placeholder="t('guide.search')" :aria-label="t('guide.search')" />

        <p v-if="loading" class="state">{{ t('common.loading') }}</p>
        <p v-else-if="filtered.length === 0" class="state">{{ t('guide.empty') }}</p>

        <div v-for="group in grouped" :key="group.category" class="group">
          <h4>{{ group.category }}</h4>
          <ul>
            <li v-for="article in group.items" :key="article.id">
              <button
                class="index-link"
                :class="{ active: article.id === selectedId, draft: !article.is_active }"
                type="button"
                @click="selectedId = article.id"
              >
                {{ title(article) }}
                <span v-if="!article.is_active" class="pill">{{ t('guide.draft') }}</span>
              </button>
            </li>
          </ul>
        </div>
      </aside>

      <article class="card reader">
        <p v-if="!selected" class="state">{{ t('guide.selectPrompt') }}</p>
        <template v-else>
          <header class="reader-head">
            <div>
              <h3>{{ title(selected) }}</h3>
              <small class="muted ltr">{{ selected.code }}</small>
            </div>
            <div class="reader-actions no-print">
              <button v-can="'user_guide.edit'" class="ghost" type="button" @click="startEdit(selected)">
                {{ t('common.edit') }}
              </button>
              <button v-can="'user_guide.delete'" class="ghost danger" type="button" @click="remove(selected)">
                {{ t('common.delete') }}
              </button>
            </div>
          </header>
          <!-- Plain text only. See the note at the top of this file. -->
          <p v-for="(block, index) in paragraphs(selected)" :key="index" class="para">{{ block }}</p>
        </template>
      </article>
    </div>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
h2 { margin: 0; color: var(--color-nav); font-size: 1.2rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }
.heading-actions { display: flex; gap: .5rem; }

.layout { display: grid; grid-template-columns: minmax(200px, 260px) 1fr; gap: 1rem; align-items: start; }
@media (max-width: 800px) { .layout { grid-template-columns: 1fr; } }

.index { padding: 1rem; }
.index input { width: 100%; padding: .45rem .6rem; margin-bottom: .75rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
.group h4 { margin: .75rem 0 .35rem; color: var(--color-muted); font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.group ul { list-style: none; margin: 0; padding: 0; }
.index-link { display: flex; align-items: center; justify-content: space-between; gap: .4rem; width: 100%; padding: .4rem .5rem; border: 0; border-radius: var(--radius-lg); background: none; color: var(--color-black-700); font-size: .85rem; text-align: start; cursor: pointer; }
.index-link:hover { background: var(--color-surface-hover); }
.index-link.active { background: var(--color-surface-hover); color: var(--color-nav); font-weight: 600; }
.index-link.draft { color: var(--color-muted); }

.reader { padding: 1.5rem 1.75rem; }
.reader-head { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; padding-bottom: .75rem; border-bottom: 1px solid var(--color-border); flex-wrap: wrap; }
.reader-head h3 { margin: 0; color: var(--color-nav); font-size: 1.05rem; }
.reader-actions { display: flex; gap: .35rem; }
.para { margin: 0 0 .9rem; line-height: 1.85; font-size: .9rem; white-space: pre-line; }

.editor { padding: 1.25rem; }
.editor h3 { margin: 0 0 1rem; font-size: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
.grid.bodies { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
label { display: flex; flex-direction: column; gap: .3rem; color: var(--color-black-700); font-size: .85rem; }
.checkbox { flex-direction: row; align-items: center; gap: .4rem; }
input, textarea { min-width: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); font: inherit; }
textarea { resize: vertical; line-height: 1.7; }
input:focus, textarea:focus { outline: 2px solid var(--color-nav); outline-offset: 1px; }
.hint, .muted { color: var(--color-muted); font-size: .72rem; }
.field-error { color: #b91c1c; font-size: .72rem; }
.actions { display: flex; gap: .5rem; margin-top: 1rem; }

button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; color: #fff; background: var(--color-nav); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
.ghost.danger { color: #b91c1c; border-color: #fecaca; }
button:disabled { cursor: not-allowed; opacity: .55; }
.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid #fecaca; border-radius: var(--radius-lg); color: #b91c1c; background: #fef2f2; }
.alert .ghost { margin-inline-start: .5rem; }
.state { padding: .75rem .25rem; margin: 0; color: var(--color-muted); font-size: .85rem; }
.pill { padding: .05rem .4rem; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: .68rem; }

@media print {
  .layout { display: block; }
  .index { display: none; }
  .reader { border: 0; padding: 0; }
}
</style>
