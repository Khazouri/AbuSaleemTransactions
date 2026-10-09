<script setup>
// Stage 68 — [D] Art. 21 / [E] stage 08: the pre-meeting legal review.
// The legal member's queue plus the review card itself — Appendix 22's
// بطاقة السند القانوني and النموذج 06's five-outcome verdict.
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()

const name = (row) => {
  if (!row) return t('common.none')
  return locale.value === 'ar' ? row.name_ar || row.name_en : row.name_en || row.name_ar
}

function date(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' })
    .format(new Date(value))
}

// --- The queue -------------------------------------------------------------

const rows = ref([])
const loading = ref(false)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/legal-reviews')
    rows.value = data.data ?? []
  } catch (error) {
    loadError.value = error.response?.data?.message ?? t('meetingsUnit.legalReview.loadError')
    rows.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page legal-review">
    <div class="heading">
      <div>
        <h2>{{ t('meetingsUnit.legalReview.title') }}</h2>
        <p class="subtitle">{{ t('meetingsUnit.legalReview.subtitle') }}</p>
      </div>
    </div>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="loadError" class="alert" role="alert">
      {{ loadError }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>
    <p v-else-if="!rows.length" class="state">{{ t('meetingsUnit.legalReview.empty') }}</p>

    <div v-else class="card card-flat card-pad list">
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('meetingsUnit.legalReview.table.reference') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.title') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.employee') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.requestType') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.department') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.submitted') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.rounds') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td class="ltr">{{ row.reference_number || `#${row.id}` }}</td>
              <td>{{ row.title }}</td>
              <td>{{ row.created_by?.name ?? t('common.none') }}</td>
              <td>{{ row.request_type ? name(row.request_type) : t('common.none') }}</td>
              <td>{{ row.department ? name(row.department) : t('common.none') }}</td>
              <td class="nowrap">{{ date(row.submitted_at) }}</td>
              <td>{{ row.legal_reviews_count ?? 0 }}</td>
              <td>
                <div class="row-actions">
                  <RouterLink class="ghost" :to="{ name: 'request_details', params: { id: row.id } }">
                    {{ t('meetingsUnit.legalReview.actions.openFile') }}
                  </RouterLink>
                  <!-- Decision wizard — sub-project 2: the opinion is given from the file's wizard. -->
                  <RouterLink
                    v-can="'legal_review.add'"
                    class="primary"
                    :to="{ name: 'request_details', params: { id: row.id }, query: { decide: 1 } }"
                  >
                    {{ t('decisionWizard.act') }}
                  </RouterLink>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>

<style scoped>
.list { margin-bottom: var(--space-4); }
.ltr { direction: ltr; unicode-bidi: isolate; }
.nowrap { white-space: nowrap; }
.row-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); justify-content: flex-end; }
</style>
