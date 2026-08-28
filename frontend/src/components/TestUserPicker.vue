<script setup>
/**
 * One-click sign-in for the seeded test accounts.
 *
 * WHY IT EXISTS: TEST_PLAN.md's happy path needs four different people
 * (R02 -> R05 -> R03 -> R05 -> R06 -> R07) before a request reaches
 * `in_execution`, and the six approval screens are single-role by design. A manual
 * QA pass therefore means signing in and out a dozen times; typing
 * `r05.manager@abusaleem.test` / `password` each time is the slowest part of it.
 *
 * WHY IT IS SAFE: this component holds no accounts of its own. The list, and
 * the shared password, arrive from GET /api/dev/test-users, which is a 404
 * anywhere the backend is not APP_ENV=local — so a production bundle contains
 * this markup and nothing to put in it, and LoginView never renders it.
 *
 * That is the deliberate change from the earlier build-time version: the panel
 * used to be gated on `import.meta.env.DEV` and carried its own copy of the
 * twelve addresses. The gate belonged to whichever machine ran `npm run build`,
 * so a `dist/` pointed at a local API could never show it; and the copied list
 * had to be kept in step with TestUserSeeder by hand. Both problems go away by
 * letting the server that owns the accounts be the one that lists them.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps({
  /** Rows from GET /api/dev/test-users. */
  users: { type: Array, required: true },
  /** The password every seeded account shares, per the same response. */
  password: { type: String, required: true },
  /** Artisan command to run when the accounts turn out not to be seeded. */
  seedCommand: { type: String, default: 'php artisan db:seed --class=TestUserSeeder' },
  /** Mirrors auth.loading, so a second click can't fire mid-request. */
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const { locale } = useI18n()

/**
 * Strings live here rather than in locales/*.json: this is a development tool
 * and its copy has no business shipping in the production locale bundles.
 */
const COPY = {
  ar: {
    title: 'حسابات تجريبية — بيئة التطوير فقط',
    hint: 'كلمة المرور لجميع الحسابات:',
    throttle: 'تنبيه: تسجيل الدخول محدود بـ 6 محاولات في الدقيقة، فالتنقل السريع بين الحسابات قد يُرجع خطأ 429.',
    seed: 'الحسابات المُعلَّمة أدناه غير مزروعة في قاعدة البيانات. لزراعتها:',
    notSeeded: 'غير مزروع',
    inactive: 'موقوف — يُتوقع رفض الدخول',
    show: 'إظهار',
    hide: 'إخفاء',
  },
  en: {
    title: 'Test accounts — development only',
    hint: 'Password for every account:',
    throttle: 'Note: login is rate-limited to 6 attempts per minute, so hopping quickly between accounts can return a 429.',
    seed: 'The accounts flagged below are not in the database. To seed them:',
    notSeeded: 'Not seeded',
    inactive: 'Inactive — sign-in is expected to fail',
    show: 'Show',
    hide: 'Hide',
  },
}

// Open by default — the whole point is that signing in as someone else is one
// click, and a collapsed panel would make it two.
const open = ref(true)

function pick(user) {
  emit('select', { email: user.email })
}
</script>

<template>
  <section class="dev-users">
    <header>
      <span class="title">{{ COPY[locale].title }}</span>
      <button type="button" class="toggle" @click="open = !open">
        {{ open ? COPY[locale].hide : COPY[locale].show }}
      </button>
    </header>

    <div v-if="open" class="body">
      <p class="hint">{{ COPY[locale].hint }} <code dir="ltr">{{ password }}</code></p>

      <ul>
        <li v-for="user in users" :key="user.email">
          <!-- The inactive account is listed deliberately: AuthController
               refuses it with its own message, and that refusal is something
               the test plan checks. It is marked so its failure reads as the
               expected outcome, not a broken button. -->
          <button
            type="button"
            class="user"
            :class="{ inactive: !user.is_active, missing: !user.seeded }"
            :disabled="busy"
            :title="user.is_active ? user.email : COPY[locale].inactive"
            @click="pick(user)"
          >
            <span class="roles">{{ user.roles.join(' + ') }}</span>
            <span class="who">
              <span class="name">
                {{ locale === 'ar' ? user.name_ar : user.name_en }}
                <span v-if="!user.seeded" class="flag">({{ COPY[locale].notSeeded }})</span>
              </span>
              <!-- Latin address on an Arabic page: force LTR so it reads correctly. -->
              <span class="email" dir="ltr">{{ user.email }}</span>
            </span>
          </button>
        </li>
      </ul>

      <p class="throttle">{{ COPY[locale].throttle }}</p>
      <!-- Shown only when something is actually missing, so the common case
           isn't a panel of instructions nobody needs to follow. -->
      <p v-if="users.some((u) => !u.seeded)" class="throttle">
        {{ COPY[locale].seed }}
        <code dir="ltr">{{ seedCommand }}</code>
      </p>
    </div>
  </section>
</template>

<style scoped>
.dev-users {
  width: min(400px, 92vw);
  background: var(--color-warning-bg);
  color: var(--color-warning-fg);
  border: 1px dashed var(--color-warning-border);
  border-radius: 14px;
  padding: .85rem 1rem;
  font-size: .85rem;
}
header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .5rem;
}
.title { font-weight: 600; }
.toggle {
  border: 0;
  background: none;
  padding: 0;
  color: inherit;
  font-size: .8rem;
  text-decoration: underline;
  cursor: pointer;
}
.body { margin-top: .6rem; }
.hint { margin: 0 0 .5rem; font-size: .8rem; opacity: .85; }
ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: .35rem;
  /* Twelve accounts would otherwise push the sign-in button off a laptop
     screen, which defeats the purpose of a shortcut. */
  max-height: 15rem;
  overflow-y: auto;
}
.user {
  width: 100%;
  display: flex;
  align-items: center;
  gap: .55rem;
  padding: .4rem .5rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
  color: var(--color-black-700);
  text-align: start;
  cursor: pointer;
}
.user:hover:not(:disabled) { background: var(--color-surface-hover); }
.user:disabled { opacity: .6; cursor: default; }
.user.inactive, .user.missing { border-style: dashed; color: var(--color-muted); }
.roles {
  flex-shrink: 0;
  padding: .15rem .4rem;
  border-radius: 6px;
  background: var(--color-brand);
  color: var(--color-on-brand);
  font-size: .7rem;
  font-weight: 600;
}
.user.inactive .roles, .user.missing .roles { background: var(--color-muted); }
.who { display: flex; flex-direction: column; min-width: 0; }
.name { font-size: .82rem; }
.flag { font-size: .72rem; color: var(--color-muted); }
.email {
  font-size: .72rem;
  color: var(--color-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.throttle { margin: .5rem 0 0; font-size: .75rem; opacity: .85; }
code {
  display: inline-block;
  padding: .05rem .3rem;
  border-radius: 4px;
  background: var(--color-surface);
  color: var(--color-black-700);
  font-size: .72rem;
}
</style>
