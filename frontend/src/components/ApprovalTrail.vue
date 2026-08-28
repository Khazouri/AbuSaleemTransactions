<script setup>
/** Stage 19 — signed, chronological approval evidence for one request. */
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const props = defineProps({
  approvals: { type: Array, default: () => [] },
})
const { t, locale } = useI18n()
const signatureImages = ref({})
const unavailable = ref({})
let generation = 0

const name = (item) => locale.value === 'ar'
  ? item?.name_ar || item?.name_en
  : item?.name_en || item?.name_ar
const dateTime = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(value))
  : t('common.none')

function revokeImages() {
  Object.values(signatureImages.value).forEach((url) => URL.revokeObjectURL(url))
  signatureImages.value = {}
}

async function loadSignatures() {
  const currentGeneration = ++generation
  revokeImages()
  unavailable.value = {}

  await Promise.all(props.approvals.map(async (approval) => {
    if (!approval.signature_url) return
    try {
      const { data } = await api.get(approval.signature_url, { responseType: 'blob' })
      const imageUrl = URL.createObjectURL(data)
      if (currentGeneration !== generation) {
        URL.revokeObjectURL(imageUrl)
        return
      }
      signatureImages.value = { ...signatureImages.value, [approval.id]: imageUrl }
    } catch {
      if (currentGeneration === generation) {
        unavailable.value = { ...unavailable.value, [approval.id]: true }
      }
    }
  }))
}

watch(
  () => props.approvals.map((approval) => `${approval.id}:${approval.signature_url}`).join('|'),
  loadSignatures,
  { immediate: true },
)
onUnmounted(() => {
  generation += 1
  revokeImages()
})
</script>

<template>
  <section class="card approval-trail">
    <h3>{{ t('signature.trailTitle') }}</h3>
    <p v-if="!approvals.length" class="state">{{ t('signature.trailEmpty') }}</p>
    <ol v-else>
      <li v-for="approval in approvals" :key="approval.id">
        <span class="level">{{ approval.level }}</span>
        <div class="approval-copy">
          <strong>{{ name(approval.role) }}</strong>
          <small>
            {{ t('signature.signedBy', { name: approval.approved_by?.name || t('common.none') }) }}
            · {{ dateTime(approval.approved_at) }}
          </small>
          <p v-if="approval.comment">{{ approval.comment }}</p>
          <img
            v-if="signatureImages[approval.id]"
            :src="signatureImages[approval.id]"
            :alt="t('signature.imageAlt', { name: approval.approved_by?.name || '' })"
          >
          <span v-else-if="approval.signature_url && !unavailable[approval.id]" class="signature-state">
            {{ t('common.loading') }}
          </span>
          <span v-else class="signature-state">{{ t('signature.unavailable') }}</span>
        </div>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.approval-trail { padding: 1.1rem; }.approval-trail h3 { margin: 0 0 .75rem; color: var(--color-brand-text); font-size: 1rem; }.approval-trail ol { display: grid; gap: .75rem; padding: 0; margin: 0; list-style: none; }.approval-trail li { display: grid; grid-template-columns: 2rem minmax(0, 1fr); gap: .7rem; padding-bottom: .75rem; border-bottom: 1px solid var(--color-border); }.approval-trail li:last-child { padding-bottom: 0; border-bottom: 0; }.level { display: grid; place-items: center; align-self: start; inline-size: 2rem; block-size: 2rem; border-radius: 50%; color: var(--color-on-brand); background: var(--color-brand); font-size: .8rem; font-weight: 700; }.approval-copy { display: grid; gap: .25rem; min-inline-size: 0; }.approval-copy strong { color: var(--color-black-700); font-size: .88rem; }.approval-copy small, .signature-state, .state { color: var(--color-muted); font-size: .76rem; }.approval-copy p { margin: .2rem 0; color: var(--color-black-700); font-size: .82rem; white-space: pre-wrap; }
/* The white here is NOT a missed token. A signature is a stored document
   drawn in dark ink on white by SignaturePad; the PNG's own background is
   opaque white, so themeing this would only put a dark frame around it. */
.approval-copy img { display: block; inline-size: min(18rem, 100%); block-size: 6rem; margin-top: .25rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: #fff; object-fit: contain; }
</style>
