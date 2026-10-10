<script setup>
/**
 * Workflow designer — slice 3. One outcome. A built-in rule can only be
 * pointed somewhere else in this slice (that is how a custom step is put in
 * the path); an outcome the admin added is fully editable. Exactly one actor
 * is chosen: a role, صاحب العلاقة's direct manager, or the filer.
 */
import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { actionLabel } from '../../lib/workflowActions'

const props = defineProps({
  rule: { type: Object, required: true },
  builtIn: { type: Boolean, default: false },
  stages: { type: Array, required: true },
  roles: { type: Array, required: true },
  statuses: { type: Array, required: true },
  error: { type: String, default: '' },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['submit', 'cancel'])
const { t, te, locale } = useI18n()
const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar)

const form = reactive({
  ...props.rule,
  actor: props.rule.requires_submitter_manager ? 'manager' : props.rule.requires_creator ? 'creator' : 'role',
})
// Workflow designer — slice 4. Only a step with a checklist can have an outcome waiting on it.
const fromChecklist = computed(() => (props.stages.find((stage) => stage.code === form.from)?.checklist ?? []).length > 0)
const title = computed(() => actionLabel(props.rule, { t, te, locale: locale.value }))
// Every account holds R01, so the server refuses it as an actor; the filer is «مقدم الطلب».
const actorRoles = computed(() => props.roles.filter((role) => role.code !== 'R01'))

function submit() {
  const { actor, ...rule } = form
  emit('submit', props.builtIn ? rule : {
    ...rule,
    required_role_id: actor === 'role' ? rule.required_role_id : null,
    requires_submitter_manager: actor === 'manager',
    requires_creator: actor === 'creator',
    requires_checks: fromChecklist.value && actor !== 'creator' && Boolean(rule.requires_checks),
  })
}
</script>

<template>
  <form class="outcome-form" @submit.prevent="submit">
    <p v-if="error" class="alert" role="alert">{{ error }}</p>
    <p v-if="builtIn" class="hint">{{ t('workflows.edit.builtinLocked', { action: title }) }}</p>
    <div class="field-grid">
      <template v-if="!builtIn">
        <label>
          <span>{{ t('workflows.edit.labelAr') }}<span class="required-mark" aria-hidden="true">*</span></span>
          <input v-model="form.label_ar" required maxlength="255" dir="auto" />
        </label>
        <label>
          {{ t('workflows.edit.labelEn') }}
          <input v-model="form.label_en" maxlength="255" dir="auto" />
        </label>
      </template>
      <label>
        <span>{{ t('workflows.edit.to') }}<span class="required-mark" aria-hidden="true">*</span></span>
        <select v-model="form.to" required>
          <option v-for="item in stages" :key="item.code" :value="item.code">{{ item.order_no }}. {{ name(item) }}</option>
        </select>
      </label>
      <label v-if="!builtIn">
        {{ t('workflows.edit.setStatus') }}
        <select v-model="form.set_status_id">
          <option :value="null">{{ t('workflows.edit.keepStatus') }}</option>
          <option v-for="status in statuses" :key="status.id" :value="status.id">{{ name(status) }}</option>
        </select>
      </label>
    </div>
    <template v-if="!builtIn">
      <fieldset class="actor">
        <legend>{{ t('workflows.edit.actor') }}<span class="required-mark" aria-hidden="true">*</span></legend>
        <label class="check"><input v-model="form.actor" type="radio" value="role" /> {{ t('workflows.edit.actorRole') }}</label>
        <div v-if="form.actor === 'role'" class="field-grid">
          <select v-model="form.required_role_id" required :aria-label="t('workflows.edit.actorRole')">
            <option v-for="role in actorRoles" :key="role.id" :value="role.id">{{ name(role) }}</option>
          </select>
        </div>
        <label class="check"><input v-model="form.actor" type="radio" value="manager" /> {{ t('workflows.actor.manager') }}</label>
        <label class="check"><input v-model="form.actor" type="radio" value="creator" /> {{ t('workflows.actor.creator') }}</label>
      </fieldset>
      <label class="check"><input v-model="form.requires_comment" type="checkbox" /> {{ t('workflows.edit.requiresComment') }}</label>
      <label v-if="fromChecklist" class="check"><input v-model="form.requires_checks" type="checkbox" :disabled="form.actor === 'creator'" /> {{ t('workflows.edit.requiresChecks') }}</label>
      <label class="check"><input v-model="form.is_exception" type="checkbox" /> {{ t('workflows.edit.isException') }}</label>
    </template>
    <div class="modal-actions">
      <button class="ghost" type="button" @click="emit('cancel')">{{ t('common.cancel') }}</button>
      <button class="primary" type="submit" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
    </div>
  </form>
</template>

<style scoped>
.outcome-form { display: grid; gap: var(--space-3); }
.hint { margin: 0; color: var(--color-muted); }
.actor { display: grid; gap: var(--space-2); margin: 0; padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.actor legend { padding-inline: var(--space-1); font-size: var(--text-sm); }
.check { display: flex; align-items: center; gap: var(--space-2); font-size: var(--text-sm); }
</style>
