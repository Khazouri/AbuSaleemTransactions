<script setup>
/**
 * Dashboard (لوحة التحكم).
 *
 * Renders inside AppLayout, so it has no header or logout button of its own —
 * the top bar owns those. For now it confirms the authenticated session works
 * by showing who is signed in; Stage 24 replaces this with live KPIs.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'

const { t, locale } = useI18n()
const auth = useAuthStore()

/** Department name in the active language, or an em dash if unassigned. */
const departmentName = computed(() => {
  const dept = auth.user?.department
  if (!dept) return t('common.none')
  return locale.value === 'ar'
    ? dept.name_ar || dept.name_en
    : dept.name_en || dept.name_ar
})
</script>

<template>
  <section class="card">
    <h2>{{ t('auth.welcome') }}، {{ auth.user?.name }}</h2>

    <dl>
      <div>
        <dt>{{ t('account.name') }}</dt>
        <dd>{{ auth.user?.name }}</dd>
      </div>
      <div>
        <dt>{{ t('account.email') }}</dt>
        <!-- Latin text inside an RTL page needs an explicit direction. -->
        <dd class="ltr">{{ auth.user?.email }}</dd>
      </div>
      <div>
        <dt>{{ t('account.department') }}</dt>
        <dd>{{ departmentName }}</dd>
      </div>
      <div>
        <dt>{{ t('account.roles') }}</dt>
        <dd>
          <span v-for="role in auth.user?.roles ?? []" :key="role.id" class="badge">
            {{ role.code }} — {{ locale === 'ar' ? role.name_ar : role.name_en }}
          </span>
        </dd>
      </div>
    </dl>
  </section>
</template>

<style scoped>
.card {
  max-width: 640px;
  padding: 1.5rem;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
}
h2 { margin: 0 0 1.25rem; font-size: 1.1rem; color: #0f5132; }
dl { margin: 0; display: grid; gap: .75rem; }
dl > div { display: grid; grid-template-columns: 130px 1fr; gap: .5rem; align-items: start; }
dt { color: #6b7280; font-size: .875rem; }
dd { margin: 0; }
.badge {
  display: inline-block;
  padding: .2rem .55rem;
  margin-inline-end: .35rem;
  background: #ecfdf5;
  color: #065f46;
  border: 1px solid #a7f3d0;
  border-radius: 999px;
  font-size: .8rem;
}
</style>
