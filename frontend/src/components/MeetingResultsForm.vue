<script setup>
/**
 * 2026-10-18 — the paper sitting's results. The مقرر copies, item by item,
 * the توصية the committee wrote on paper, its note and its جهة الاعتماد, and
 * attaches the scanned محضر; MeetingWizard posts it all in one submit. A
 * deferral goes back to the pending list, so it names no authority. An appeal
 * row names none either, and always states its reason.
 */
import { useI18n } from 'vue-i18n'
import FileDropZone from './FileDropZone.vue'

defineProps({
  items: { type: Array, required: true },
  disabled: { type: Boolean, default: false },
})
const rows = defineModel('rows', { type: Object, required: true })
const minutes = defineModel('minutes', { type: Object, default: null })
const { t, locale } = useI18n()

const RECOMMENDATIONS = ['approve', 'decline', 'defer']
const isAppeal = (item) => item.item_type === 'appeal'
const optionLabel = (item, option) => t(`paperMeeting.${isAppeal(item) ? 'appealRecommendations' : 'recommendations'}.${option}`)
const noteRequired = (item) => isAppeal(item)
  || (Boolean(rows.value[item.id].recommendation) && rows.value[item.id].recommendation !== 'approve')
const typeName = (type) => (type ? (locale.value === 'ar' ? type.name_ar : type.name_en || type.name_ar) : '')

function choose(files) {
  minutes.value = files[0] ?? null
}
</script>

<template>
  <div class="results">
    <ol class="items">
      <li v-for="(item, index) in items" :key="item.id" class="item">
        <p v-if="isAppeal(item)" class="item-head">
          <span class="number">{{ index + 1 }}</span>
          <span class="ltr reference">{{ item.appeal?.original_request?.reference_number }}</span>
          <strong>{{ t('paperMeeting.appealOn', { title: item.appeal?.original_request?.title ?? '' }) }}</strong>
          <span class="meta">{{ item.appeal?.appellant?.name }}</span>
        </p>
        <p v-else class="item-head">
          <span class="number">{{ index + 1 }}</span>
          <span class="ltr reference">{{ item.request?.reference_number }}</span>
          <strong>{{ item.request?.title }}</strong>
          <span class="meta">{{ item.request?.subject_name }}<template v-if="item.request?.request_type"> · {{ typeName(item.request.request_type) }}</template></span>
        </p>
        <p v-if="item.request?.legal_recommendation" class="legal">
          {{ t('paperMeeting.legalRecommendation', {
            recommendation: t(`meetingsUnit.legalReview.recommendations.${item.request.legal_recommendation.recommendation}`),
            authority: t(`decisions.approvingAuthority.${item.request.legal_recommendation.approving_authority}`),
          }) }}
        </p>

        <fieldset class="choice" :disabled="disabled">
          <legend>
            <span>{{ t('paperMeeting.recommendation') }}<span class="required-mark" aria-hidden="true">*</span></span>
          </legend>
          <label v-for="option in RECOMMENDATIONS" :key="option" class="pill-choice" :class="{ chosen: rows[item.id].recommendation === option }">
            <input v-model="rows[item.id].recommendation" type="radio" :name="`recommendation-${item.id}`" :value="option" required />
            <span>{{ optionLabel(item, option) }}</span>
          </label>
        </fieldset>

        <div class="fields">
          <label v-if="!isAppeal(item)">
            <span>{{ t('paperMeeting.authority') }}<span v-if="rows[item.id].recommendation !== 'defer'" class="required-mark" aria-hidden="true">*</span></span>
            <select
              v-model="rows[item.id].approving_authority"
              :disabled="disabled || rows[item.id].recommendation === 'defer'"
              :required="rows[item.id].recommendation !== 'defer'"
            >
              <option value="dean">{{ t('decisions.approvingAuthority.dean') }}</option>
              <option value="minister">{{ t('decisions.approvingAuthority.minister') }}</option>
            </select>
          </label>
          <label>
            <span>
              {{ noteRequired(item) ? t('paperMeeting.noteRequired') : t('paperMeeting.noteOptional') }}<span v-if="noteRequired(item)" class="required-mark" aria-hidden="true">*</span>
            </span>
            <textarea
              v-model="rows[item.id].note"
              rows="2"
              maxlength="2000"
              :disabled="disabled"
              :required="noteRequired(item)"
            />
          </label>
        </div>
      </li>
    </ol>

    <div class="minutes">
      <p class="label">{{ t('paperMeeting.minutes') }}<span class="required-mark" aria-hidden="true">*</span></p>
      <FileDropZone accept=".pdf,.jpg,.jpeg,.png" :disabled="disabled" @files="choose" />
      <p v-if="minutes" class="chosen-file">
        <span dir="auto">{{ minutes.name }}</span>
        <button class="link" type="button" :disabled="disabled" @click="minutes = null">{{ t('paperMeeting.removeFile') }}</button>
      </p>
    </div>
  </div>
</template>

<style scoped>
.results { display: grid; gap: var(--space-4); }
.items { display: grid; gap: var(--space-3); margin: 0; padding: 0; list-style: none; }
.item { display: grid; gap: var(--space-2); padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); min-inline-size: 0; }
.item-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-2); margin: 0; }
.number { display: inline-grid; place-items: center; min-inline-size: 1.75rem; block-size: 1.75rem; border-radius: var(--radius-full); background: var(--color-brand); color: var(--color-on-brand); font-size: var(--text-sm); }
.reference { color: var(--color-black-600); font-size: var(--text-sm); }
.meta { color: var(--color-black-600); font-size: var(--text-sm); }
.legal { margin: 0; color: var(--color-info-fg); font-size: var(--text-sm); }
.choice { display: flex; flex-wrap: wrap; gap: var(--space-2); margin: 0; padding: 0; border: 0; min-inline-size: 0; }
.choice legend { margin-block-end: var(--space-1); padding: 0; color: var(--color-black-700); font-size: var(--text-sm); }
.pill-choice { display: inline-flex; align-items: center; gap: var(--space-1); padding: var(--space-1) var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-full); cursor: pointer; }
.pill-choice.chosen { border-color: var(--color-brand); color: var(--color-brand-text); }
.pill-choice input { accent-color: var(--color-brand); }
.fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(14rem, 100%), 1fr)); gap: var(--space-3); }
.fields label { display: grid; gap: var(--space-1); min-inline-size: 0; color: var(--color-black-700); font-size: var(--text-sm); }
.minutes { display: grid; gap: var(--space-2); }
.label { margin: 0; color: var(--color-black-700); font-weight: 600; }
.chosen-file { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin: 0; }
</style>
