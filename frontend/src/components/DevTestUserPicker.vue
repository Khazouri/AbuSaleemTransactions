<script setup>
/**
 * One-click sign-in for the seeded test accounts — DEVELOPMENT ONLY.
 *
 * WHY IT EXISTS: TEST_PLAN.md's happy path needs four different people
 * (R02 -> R05 -> R03 -> R05 -> R06 -> R07) before a transaction reaches
 * `archived`, and the six approval screens are single-role by design. A manual
 * QA pass therefore means signing in and out a dozen times; typing
 * `r05.manager@abusaleem.test` / `password` each time is the slowest part of it.
 *
 * WHY IT IS SAFE: this component is never reached in a production build.
 * LoginView imports it through `defineAsyncComponent` inside an
 * `import.meta.env.DEV` branch, which Rollup resolves to `false` and removes —
 * taking this file and the known-password addresses below out of the bundle
 * entirely, rather than merely hiding them behind a v-if.
 *
 * WHY THE LIST IS HARD-CODED: it mirrors database/seeders/TestUserSeeder.php.
 * The alternative — an endpoint that lists accounts — would have to exist on
 * the real API, where enumerating users is exactly what the login endpoint
 * already refuses to do (see AuthController's identical-message comment).
 *
 * Keep in step with TestUserSeeder::TEST_USERS if that list ever changes.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps({
  /** Mirrors auth.loading, so a second click can't fire mid-request. */
  busy: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const { locale } = useI18n()

// Every seeded test account shares this password (TestUserSeeder hashes the
// literal string). Shown in the panel because a broken auto-fill should be
// recoverable by typing.
const PASSWORD = 'password'

/**
 * Strings live here rather than in locales/*.json: this is a dev-only tool and
 * its copy has no business shipping in the production locale bundles.
 */
const COPY = {
  ar: {
    title: 'حسابات تجريبية — بيئة التطوير فقط',
    hint: `كلمة المرور لجميع الحسابات: ${PASSWORD}`,
    throttle: 'تنبيه: تسجيل الدخول محدود بـ 6 محاولات في الدقيقة، فالتنقل السريع بين الحسابات قد يُرجع خطأ 429.',
    seed: 'إذا ظهر أن البيانات غير صحيحة، فالحسابات لم تُزرع بعد:',
    inactive: 'موقوف — يُتوقع رفض الدخول',
    show: 'إظهار',
    hide: 'إخفاء',
  },
  en: {
    title: 'Test accounts — development only',
    hint: `Password for every account: ${PASSWORD}`,
    throttle: 'Note: login is rate-limited to 6 attempts per minute, so hopping quickly between accounts can return a 429.',
    seed: 'If sign-in reports bad credentials, the accounts have not been seeded yet:',
    inactive: 'Inactive — sign-in is expected to fail',
    show: 'Show',
    hide: 'Hide',
  },
}

/**
 * [email, role codes, Arabic name, English label, active]
 *
 * The inactive account is listed deliberately: AuthController refuses it with
 * its own message, and that refusal is something the test plan checks. It is
 * marked so its failure reads as the expected outcome, not a broken button.
 */
const USERS = [
  { email: 'r01.employee@abusaleem.test', roles: ['R01'], ar: 'موظف تجريبي', en: 'Employee', active: true },
  { email: 'r02.reviewer@abusaleem.test', roles: ['R02'], ar: 'مقرر تجريبي', en: 'Reviewer', active: true },
  { email: 'r03.head@abusaleem.test', roles: ['R03'], ar: 'رئيس اللجنة التجريبي', en: 'Committee head', active: true },
  { email: 'r04.member1@abusaleem.test', roles: ['R04'], ar: 'عضو اللجنة الأول', en: 'Committee member 1', active: true },
  { email: 'r04.member2@abusaleem.test', roles: ['R04'], ar: 'عضو اللجنة الثاني', en: 'Committee member 2', active: true },
  { email: 'r04.member3@abusaleem.test', roles: ['R04'], ar: 'عضو اللجنة الثالث', en: 'Committee member 3', active: true },
  { email: 'r05.manager@abusaleem.test', roles: ['R05'], ar: 'مدير الشؤون الإدارية', en: 'Admin affairs manager', active: true },
  { email: 'r06.ministry@abusaleem.test', roles: ['R06'], ar: 'مندوب وزارة الحكم المحلي', en: 'Ministry delegate', active: true },
  { email: 'r07.director@abusaleem.test', roles: ['R07'], ar: 'المدير العام التجريبي', en: 'Director general', active: true },
  { email: 'r08.sysadmin@abusaleem.test', roles: ['R08'], ar: 'مدير نظام تجريبي', en: 'System admin', active: true },
  { email: 'multi.role@abusaleem.test', roles: ['R03', 'R04'], ar: 'رئيس وعضو لجنة', en: 'Head + member (union check)', active: true },
  { email: 'inactive.user@abusaleem.test', roles: ['R01'], ar: 'مستخدم موقوف', en: 'Suspended user', active: false },
]

// Open by default — the whole point is that signing in as someone else is one
// click, and a collapsed panel would make it two.
const open = ref(true)

function pick(user) {
  emit('select', { email: user.email, password: PASSWORD })
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
      <p class="hint">{{ COPY[locale].hint }}</p>

      <ul>
        <li v-for="user in USERS" :key="user.email">
          <button
            type="button"
            class="user"
            :class="{ inactive: !user.active }"
            :disabled="busy"
            :title="!user.active ? COPY[locale].inactive : user.email"
            @click="pick(user)"
          >
            <span class="roles">{{ user.roles.join(' + ') }}</span>
            <span class="who">
              <span class="name">{{ locale === 'ar' ? user.ar : user.en }}</span>
              <!-- Latin address on an Arabic page: force LTR so it reads correctly. -->
              <span class="email" dir="ltr">{{ user.email }}</span>
            </span>
          </button>
        </li>
      </ul>

      <p class="throttle">{{ COPY[locale].throttle }}</p>
      <p class="throttle">
        {{ COPY[locale].seed }}
        <code dir="ltr">php artisan db:seed --class=TestUserSeeder</code>
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
.user.inactive { border-style: dashed; color: var(--color-muted); }
.roles {
  flex-shrink: 0;
  padding: .15rem .4rem;
  border-radius: 6px;
  background: var(--color-brand);
  color: var(--color-on-brand);
  font-size: .7rem;
  font-weight: 600;
}
.user.inactive .roles { background: var(--color-muted); }
.who { display: flex; flex-direction: column; min-width: 0; }
.name { font-size: .82rem; }
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
