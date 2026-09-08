<script setup>
// Stage 78 — [D] Art. 105's إيقاف إجرائي: two forms in one panel, because the
// article names a cause and a consequence recorded at two different moments.
//
// Where the file goes on lifting is deliberately not a field — the two
// outcomes carry their own routing, so "the doubt was cleared" cannot be
// paired with a move to the committee. Stage 77's ApprovalReturnPanel makes
// the same call for the same reason; the panel only says which it will be.
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { SUSPENSION_GROUNDS, SUSPENSION_RESOLUTIONS } from '../lib/controlGates'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // Every round, oldest first — Art. 105's loop has no limit.
  suspensions: { type: Array, default: () => [] },
  // Why a suspension cannot be recorded right now; null means it can.
  refusal: { type: String, default: null },
  // The unresolved round's id, if the file is being held by one.
  openId: { type: [Number, String], default: null },
})

const emit = defineEmits(['updated'])

const { t } = useI18n()

const open = ref(false)
const lifting = ref(false)
const saving = ref(false)
const error = ref('')

const form = reactive({ ground: SUSPENSION_GROUNDS[0], detail: '' })
const lift = reactive({ resolution_action: SUSPENSION_RESOLUTIONS[0], resolution_note: '' })

watch([open, lifting], () => {
  error.value = ''
})

const canSubmit = computed(() => form.detail.trim() !== '')

function describe(requestError) {
  const errors = requestError.response?.data?.errors
  return errors
    ? Object.values(errors).flat().join(' — ')
    : (requestError.response?.data?.message ?? t('controlGates.error'))
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.requestId}/suspend`, { ...form })
    open.value = false
    form.detail = ''
    emit('updated', data.data)
  } catch (requestError) {
    error.value = describe(requestError)
  } finally {
    saving.value = false
  }
}

async function submitLift() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.requestId}/suspend/lift`, { ...lift })
    lifting.value = false
    lift.resolution_note = ''
    emit('updated', data.data)
  } catch (requestError) {
    error.value = describe(requestError)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="gate-panel">
    <!-- The register itself. Art. 105's loop can run more than once, and an
         earlier round staying readable is the point of keeping a history. -->
    <ul v-if="suspensions.length" class="rounds">
      <li v-for="round in suspensions" :key="round.id">
        <p class="round-head">
          <strong>{{ t(`controlGates.suspension.grounds.${round.ground}`) }}</strong>
          <span v-if="round.suspended_at">{{ round.suspended_at.slice(0, 10) }}</span>
        </p>
        <p class="round-detail">{{ round.detail }}</p>
        <p v-if="round.resolution_action" class="round-detail">
          {{ t(`controlGates.suspension.resolutions.${round.resolution_action}`) }}
          <template v-if="round.resolution_note"> — {{ round.resolution_note }}</template>
        </p>
        <p v-else class="round-open">{{ t('controlGates.suspension.stillOpen') }}</p>
      </li>
    </ul>

    <template v-if="openId">
      <p class="alert">{{ t('controlGates.suspension.pendingLift') }}</p>

      <button
        v-if="!lifting"
        v-can="'meeting_outputs.edit'"
        class="primary compact"
        type="button"
        @click="lifting = true"
      >
        {{ t('controlGates.suspension.liftAction') }}
      </button>

      <form v-else class="gate-form" @submit.prevent="submitLift">
        <p class="hint">{{ t('controlGates.suspension.liftNote') }}</p>

        <label>
          <span>{{ t('controlGates.suspension.fields.resolution_action') }} *</span>
          <select v-model="lift.resolution_action">
            <option v-for="action in SUSPENSION_RESOLUTIONS" :key="action" :value="action">
              {{ t(`controlGates.suspension.resolutions.${action}`) }}
            </option>
          </select>
        </label>
        <label>
          <span>{{ t('controlGates.suspension.fields.resolution_note') }}</span>
          <textarea v-model="lift.resolution_note" rows="3" />
        </label>

        <p v-if="error" class="alert">{{ error }}</p>

        <div class="actions">
          <button class="primary" type="submit" :disabled="saving">
            {{ saving ? t('controlGates.saving') : t('controlGates.save') }}
          </button>
          <button type="button" @click="lifting = false">{{ t('controlGates.cancel') }}</button>
        </div>
      </form>
    </template>

    <template v-else>
      <p v-if="refusal" class="hint">{{ refusal }}</p>

      <button
        v-else-if="!open"
        v-can="'meeting_outputs.edit'"
        class="compact destructive"
        type="button"
        @click="open = true"
      >
        {{ t('controlGates.suspension.action') }}
      </button>

      <form v-if="open" class="gate-form" @submit.prevent="submit">
        <p class="hint">{{ t('controlGates.suspension.formNote') }}</p>

        <label>
          <span>{{ t('controlGates.suspension.fields.ground') }} *</span>
          <select v-model="form.ground">
            <option v-for="ground in SUSPENSION_GROUNDS" :key="ground" :value="ground">
              {{ t(`controlGates.suspension.grounds.${ground}`) }}
            </option>
          </select>
        </label>
        <label>
          <span>{{ t('controlGates.suspension.fields.detail') }} *</span>
          <textarea v-model="form.detail" rows="3" required />
        </label>

        <p v-if="error" class="alert">{{ error }}</p>

        <div class="actions">
          <button class="primary" type="submit" :disabled="saving || !canSubmit">
            {{ saving ? t('controlGates.saving') : t('controlGates.save') }}
          </button>
          <button type="button" @click="open = false">{{ t('controlGates.cancel') }}</button>
        </div>
      </form>
    </template>
  </div>
</template>

<style scoped>
.gate-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.gate-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  background: var(--color-surface);
}

.gate-form label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
}

.gate-form select,
.gate-form textarea {
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}

.rounds {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.rounds li {
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  font-size: 0.85rem;
}

.round-head {
  margin: 0 0 0.25rem;
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
}

.round-detail {
  margin: 0;
  color: var(--color-black-500);
}

.round-open {
  margin: 0.25rem 0 0;
  color: var(--color-warning-fg);
  font-weight: 600;
}

.hint {
  margin: 0;
  font-size: 0.8rem;
  color: var(--color-black-500);
}

.actions {
  display: flex;
  gap: 0.5rem;
}

.alert {
  margin: 0;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--color-warning-border);
  border-radius: 0.35rem;
  background: var(--color-warning-bg);
  color: var(--color-warning-fg);
  font-size: 0.85rem;
}
</style>
