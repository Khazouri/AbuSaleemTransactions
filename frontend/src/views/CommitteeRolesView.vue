<script setup>
/**
 * Stage 103 — «المناصب الفردية». R08 names the مقرر and العميد; the مقرر names
 * the other four seat holders. Which rows a viewer may assign is the server's
 * `can_assign`, so this screen never guesses at the rule.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppModal from '../components/AppModal.vue'

const { t, locale } = useI18n()
const seats = ref([])
const users = ref([])
const loading = ref(false)
const loadError = ref(null)
const assigning = ref(null)
const userId = ref('')
const saving = ref(false)
const formError = ref(null)

// العميد is a post, not a committee seat, so its label lives on this page.
const postName = (seat) => (['dean', 'minister'].includes(seat) ? t(`committeeRoles.${seat}`) : t(`committees.seats.${seat}`))
const roleName = (role) => (locale.value === 'ar' ? role.name_ar : role.name_en || role.name_ar)

function apply(data) {
  seats.value = data.seats
  users.value = data.users
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/committee-roles')
    apply(data.data)
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

function startAssign(seat) {
  assigning.value = seat
  userId.value = seat.holder?.id ?? ''
  formError.value = null
}

function cancelAssign() {
  assigning.value = null
}

const replacing = computed(() => {
  const holder = assigning.value?.holder
  return holder && userId.value && holder.id !== userId.value ? holder : null
})

async function save() {
  saving.value = true
  formError.value = null
  try {
    const { data } = await api.put(`/committee-roles/${assigning.value.seat}`, { user_id: userId.value })
    apply(data.data)
    cancelAssign()
  } catch (e) {
    formError.value = e?.response?.data?.message ?? t('requestDetail.actionFailed')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page">
    <div class="heading">
      <div>
        <h2>{{ t('committeeRoles.title') }}</h2>
        <p class="subtitle">{{ t('committeeRoles.subtitle') }}</p>
      </div>
    </div>

    <AppModal
      v-if="assigning"
      :title="t('committeeRoles.assignTitle', { seat: postName(assigning.seat) })"
      @close="cancelAssign"
    >
      <form class="form" @submit.prevent="save">
        <p v-if="formError" class="alert">{{ formError }}</p>
        <label>
          <span>{{ t('committeeRoles.holder') }}<span class="required-mark" aria-hidden="true">*</span></span>
          <select v-model="userId" required>
            <option value="">{{ t('committeeRoles.chooseUser') }}</option>
            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
          </select>
        </label>
        <p v-if="replacing" class="hint">{{ t('committeeRoles.replaces', { name: replacing.name }) }}</p>
        <div class="modal-actions">
          <button class="ghost" type="button" @click="cancelAssign">{{ t('common.cancel') }}</button>
          <button class="primary" type="submit" :disabled="saving || !userId">
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </form>
    </AppModal>

    <div class="card card-flat card-pad list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="alert">{{ t('nav.error') }} <button class="ghost" @click="load">{{ t('common.retry') }}</button></p>
      <table v-else class="data-table">
        <thead>
          <tr>
            <th>{{ t('committeeRoles.seat') }}</th>
            <th>{{ t('committeeRoles.role') }}</th>
            <th>{{ t('committeeRoles.holder') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="seat in seats" :key="seat.seat">
            <td>{{ postName(seat.seat) }}</td>
            <td><span class="pill">{{ seat.role.code }}</span> {{ roleName(seat.role) }}</td>
            <td :class="{ vacant: !seat.holder }">{{ seat.holder?.name ?? t('committeeRoles.vacant') }}</td>
            <td class="row-actions">
              <button v-if="seat.can_assign" class="ghost" type="button" @click="startAssign(seat)">
                {{ t('committeeRoles.assign') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
select { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); }
.list { overflow-x: auto; }
.vacant { color: var(--color-black-500); }
.row-actions { display: flex; justify-content: flex-end; gap: var(--space-2); white-space: nowrap; }
</style>
