<script setup>
// Stage 78 — [D] Art. 105's إيقاف إجرائي register. Read-only since decision
// wizard sub-project 3: suspending and lifting are wizard acts
// (acts/SuspendForm.vue, acts/LiftForm.vue).
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  // Every round, oldest first — Art. 105's loop has no limit.
  suspensions: { type: Array, default: () => [] },
})

const { t } = useI18n()
const holding = computed(() => props.suspensions.some((round) => !round.resolution_action))
</script>

<template>
  <div class="gate-panel">
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
    <p v-if="holding" class="alert warning">{{ t('controlGates.suspension.pendingLift') }}</p>
  </div>
</template>
