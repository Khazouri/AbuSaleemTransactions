<script setup>
import { ref, onMounted } from 'vue'
import api from './lib/api'

const state = ref('loading') // loading | ok | error
const response = ref(null)
const error = ref(null)

async function ping() {
  state.value = 'loading'
  error.value = null
  try {
    const { data } = await api.get('/ping')
    response.value = data
    state.value = 'ok'
  } catch (e) {
    error.value = e?.message ?? String(e)
    state.value = 'error'
  }
}

onMounted(ping)
</script>

<template>
  <main class="wrap" dir="rtl">
    <h1>بلدية أبو سليم — نظام إدارة المعاملات</h1>
    <p class="sub">Stage 1 — API ↔ SPA wiring check</p>

    <section class="card">
      <template v-if="state === 'loading'">
        <span class="dot loading"></span> جارٍ الاتصال بالـ API…
      </template>

      <template v-else-if="state === 'ok'">
        <p><span class="dot ok"></span> الاتصال ناجح — API responded:</p>
        <pre>{{ JSON.stringify(response, null, 2) }}</pre>
      </template>

      <template v-else>
        <p><span class="dot err"></span> فشل الاتصال بالـ API</p>
        <pre>{{ error }}</pre>
        <p class="hint">
          تأكد من أن <code>abusaleem.test</code> يعمل عبر Homestead وأن CORS يسمح بـ
          <code>localhost:5173</code>.
        </p>
      </template>

      <button @click="ping">إعادة المحاولة</button>
    </section>
  </main>
</template>

<style scoped>
.wrap {
  max-width: 640px;
  margin: 4rem auto;
  padding: 0 1rem;
  font-family: system-ui, 'Segoe UI', Tahoma, sans-serif;
  text-align: center;
}
h1 { font-size: 1.4rem; margin-bottom: .25rem; }
.sub { color: #888; margin-top: 0; font-size: .9rem; }
.card {
  margin-top: 2rem;
  padding: 1.5rem;
  border: 1px solid #ddd;
  border-radius: 12px;
  text-align: start;
}
pre {
  background: #f5f5f5;
  color: #222;
  padding: 1rem;
  border-radius: 8px;
  overflow-x: auto;
  direction: ltr;
  text-align: left;
}
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-inline-end: 6px; }
.dot.ok { background: #16a34a; }
.dot.err { background: #dc2626; }
.dot.loading { background: #f59e0b; }
.hint { font-size: .85rem; color: #666; }
button {
  margin-top: 1rem;
  padding: .5rem 1rem;
  border: 0;
  border-radius: 8px;
  background: #0f5132;
  color: #fff;
  cursor: pointer;
}
</style>
