<script setup>
/** Stage 19 — reusable pointer/touch signature capture exported as a PNG. */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['change'])
const { t } = useI18n()
const canvas = ref(null)
const empty = ref(true)
let drawing = false
let activePointerId = null

function context() {
  return canvas.value?.getContext('2d')
}

function prepareCanvas() {
  const drawingContext = context()
  if (!drawingContext) return
  drawingContext.fillStyle = '#ffffff'
  drawingContext.fillRect(0, 0, canvas.value.width, canvas.value.height)
  drawingContext.strokeStyle = '#172554'
  drawingContext.lineWidth = 5
  drawingContext.lineCap = 'round'
  drawingContext.lineJoin = 'round'
}

function point(event) {
  const bounds = canvas.value.getBoundingClientRect()
  return {
    x: (event.clientX - bounds.left) * (canvas.value.width / bounds.width),
    y: (event.clientY - bounds.top) * (canvas.value.height / bounds.height),
  }
}

function start(event) {
  if (props.disabled) return
  drawing = true
  activePointerId = event.pointerId
  canvas.value.setPointerCapture(event.pointerId)
  const position = point(event)
  const drawingContext = context()
  drawingContext.beginPath()
  drawingContext.moveTo(position.x, position.y)
}

function draw(event) {
  if (!drawing || event.pointerId !== activePointerId || props.disabled) return
  const position = point(event)
  const drawingContext = context()
  drawingContext.lineTo(position.x, position.y)
  drawingContext.stroke()
  empty.value = false
}

function stop(event) {
  if (!drawing || event.pointerId !== activePointerId) return
  drawing = false
  canvas.value.releasePointerCapture(event.pointerId)
  activePointerId = null
  emit('change', !empty.value)
}

function clear() {
  if (props.disabled) return
  const drawingContext = context()
  drawingContext.clearRect(0, 0, canvas.value.width, canvas.value.height)
  prepareCanvas()
  empty.value = true
  emit('change', false)
}

function toFile() {
  if (empty.value || !canvas.value) return Promise.resolve(null)

  return new Promise((resolve) => {
    canvas.value.toBlob(
      (blob) => resolve(blob
        ? new File([blob], `signature-${Date.now()}.png`, { type: 'image/png' })
        : null),
      'image/png',
    )
  })
}

defineExpose({ clear, toFile })
onMounted(prepareCanvas)
</script>

<template>
  <section class="signature-pad">
    <div class="signature-heading">
      <div>
        <strong>{{ t('signature.title') }}</strong>
        <p>{{ t('signature.hint') }}</p>
      </div>
      <button type="button" :disabled="disabled || empty" @click="clear">
        {{ t('signature.clear') }}
      </button>
    </div>
    <canvas
      ref="canvas"
      width="960"
      height="330"
      :aria-label="t('signature.title')"
      :class="{ disabled }"
      @pointerdown.prevent="start"
      @pointermove.prevent="draw"
      @pointerup.prevent="stop"
      @pointercancel.prevent="stop"
    />
  </section>
</template>

<style scoped>
.signature-pad { display: grid; gap: .45rem; }.signature-heading { display: flex; align-items: start; justify-content: space-between; gap: .75rem; }.signature-heading strong { color: var(--color-black-700); font-size: .82rem; }.signature-heading p { margin: .15rem 0 0; color: var(--color-muted); font-size: .73rem; }.signature-heading button { flex: none; padding: .3rem .55rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); color: var(--color-black-700); background: var(--color-surface); cursor: pointer; }.signature-heading button:disabled { cursor: not-allowed; opacity: .5; }
/* Stays white in both themes on purpose: the canvas is exported as the stored
   signature PNG (prepareCanvas fills it white and strokes it in dark ink), so
   what is drawn here has to be what the approval trail and any printed copy
   will show. */
canvas { display: block; inline-size: 100%; block-size: auto; max-block-size: 11rem; border: 1px dashed var(--color-border-hover); border-radius: var(--radius-lg); background: #fff; cursor: crosshair; touch-action: none; }canvas.disabled { cursor: not-allowed; opacity: .65; }
</style>
