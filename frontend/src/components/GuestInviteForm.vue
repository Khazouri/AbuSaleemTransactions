<script setup>
// The مقرر's extra invitee (2026-10-16): a person and the صفة they attend in.
// Shared by the scheduling wizard (kept client-side until the meeting is
// created) and the meeting page (posted at once), so it only emits.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const props = defineProps({
  // Already invited, as a seat or a guest: the server refuses them anyway.
  excludeIds: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['add'])

const { t } = useI18n()

const users = ref([])
const userId = ref('')
const title = ref('')

const options = computed(() => users.value.filter((user) => !props.excludeIds.includes(user.id)))

onMounted(async () => {
  try {
    const { data } = await api.get('/committees/user-options')
    users.value = data.data ?? []
  } catch {
    users.value = []
  }
})

function submit() {
  const user = users.value.find((candidate) => candidate.id === userId.value)
  if (!user || !title.value.trim()) return
  emit('add', { user_id: user.id, name: user.name, guest_title: title.value.trim() })
  userId.value = ''
  title.value = ''
}
</script>

<template>
  <form class="guest-form" @submit.prevent="submit">
    <label>
      <span>{{ t('meetings.guests.user') }}<span class="required-mark" aria-hidden="true">*</span></span>
      <select v-model="userId" required>
        <option value="">{{ t('meetings.guests.chooseUser') }}</option>
        <option v-for="user in options" :key="user.id" :value="user.id">{{ user.name }}</option>
      </select>
    </label>
    <label>
      <span>{{ t('meetings.guests.jobTitle') }}<span class="required-mark" aria-hidden="true">*</span></span>
      <input v-model="title" type="text" maxlength="255" required :placeholder="t('meetings.guests.jobTitlePlaceholder')" />
    </label>
    <button class="ghost" type="submit" :disabled="busy">{{ t('meetings.guests.add') }}</button>
  </form>
</template>

<style scoped>
.guest-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(12rem, 100%), 1fr)); gap: var(--space-2); align-items: end; }
.guest-form label { display: grid; gap: .25rem; font-size: var(--text-sm); color: var(--color-black-700); }
.guest-form select,
.guest-form input { padding: .45rem .55rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: inherit; font: inherit; }
</style>
