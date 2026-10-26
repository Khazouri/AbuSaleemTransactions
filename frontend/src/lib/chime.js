/**
 * A soft two-note chime for an arriving notification.
 *
 * Synthesised with Web Audio rather than shipped as a file: two sine notes a
 * fifth apart, a slow fade and a low volume read as "something arrived" without
 * startling anyone in a quiet office. One context is reused, because browsers
 * cap how many a page may open.
 *
 * Browsers refuse to start audio before the page has had a click or key press.
 * By the time a poll finds a notification the user has signed in, so that is
 * nearly always true; when it isn't, the chime is simply skipped.
 */
let context = null

export function playChime() {
  try {
    context ??= new AudioContext()
    if (context.state === 'suspended') context.resume()

    const start = context.currentTime
    // C5 then G5, the second a beat later and slightly quieter.
    ;[[523.25, 0, 0.07], [783.99, 0.14, 0.05]].forEach(([frequency, delay, peak]) => {
      const tone = context.createOscillator()
      const gain = context.createGain()
      tone.type = 'sine'
      tone.frequency.value = frequency
      gain.gain.setValueAtTime(0, start + delay)
      gain.gain.linearRampToValueAtTime(peak, start + delay + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, start + delay + 1.4)
      tone.connect(gain).connect(context.destination)
      tone.start(start + delay)
      tone.stop(start + delay + 1.5)
    })
  } catch {
    // No Web Audio, or the browser refused it: the pop-up still shows.
  }
}
