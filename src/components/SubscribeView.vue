<template>
  <main class="subscribe-page">
    <div class="subscribe-card">
      <h1 class="subscribe-title">Subscribe to The Daily Earworm</h1>
      <p class="subscribe-copy">
        Get the daily feature in your inbox and be first in line for future access to the world.
      </p>

      <form v-if="!success" class="subscribe-form" @submit.prevent="subscribe">
<<<<<<< HEAD
=======
        <label class="field">
          <span>Name (optional)</span>
          <input
            v-model.trim="name"
            type="text"
            autocomplete="name"
            placeholder="Your name"
          />
        </label>
>>>>>>> rescue/mobile-cover-flicker

        <label class="field">
          <span>Email</span>
          <input
            v-model.trim="email"
            type="email"
            autocomplete="email"
            placeholder="you@example.com"
            required
          />
        </label>

        <label class="checkbox">
          <input v-model="consent" type="checkbox" required />
          <span>I agree to receive email from EWRM.</span>
        </label>

<<<<<<< HEAD
=======
        <input
          v-model.trim="company"
          type="text"
          tabindex="-1"
          autocomplete="off"
          aria-hidden="true"
          class="hp-field"
        />

>>>>>>> rescue/mobile-cover-flicker
        <button type="submit" :disabled="loading || !consent">
          {{ loading ? 'Subscribing…' : 'Subscribe' }}
        </button>

        <p v-if="error" class="msg error">{{ error }}</p>
      </form>

      <div v-else class="msg success">
        You’re subscribed. Check your inbox.
      </div>
    </div>
  </main>
</template>

<script setup>
import { ref } from 'vue'

<<<<<<< HEAD
const email = ref('')
=======
const name = ref('')
const email = ref('')
const company = ref('')
>>>>>>> rescue/mobile-cover-flicker
const consent = ref(false)
const loading = ref(false)
const success = ref(false)
const error = ref('')

async function subscribe() {
  error.value = ''

  if (!email.value) {
    error.value = 'Email is required.'
    return
  }

  loading.value = true

  try {
    const res = await fetch('/api/subscribe', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
<<<<<<< HEAD
        email: email.value,
=======
        name: name.value,
        email: email.value,
        company: company.value,
>>>>>>> rescue/mobile-cover-flicker
        consent: consent.value,
        source: 'website',
      }),
    })

    const data = await res.json().catch(() => ({}))

    if (!res.ok) {
      throw new Error(data?.error || `Request failed with ${res.status}`)
    }

    success.value = true
<<<<<<< HEAD
    email.value = ''
=======
    name.value = ''
    email.value = ''
    company.value = ''
>>>>>>> rescue/mobile-cover-flicker
    consent.value = false
  } catch (err) {
    error.value = err?.message || 'Something went wrong.'
  } finally {
    loading.value = false
  }
}
</script>
