<template>
  <div class="newsletter-redirect">
    Loading newsletter…
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { resolveNewsletter } from '../api/worldApi.js'

const route = useRoute()
const router = useRouter()

async function go() {
  try {
    const key = String(route.params.key || '').trim()
    const date = route.params.date ? String(route.params.date) : undefined

    const data = await resolveNewsletter({ key, date })

    if (data?.found && data?.anchor?.url) {
      router.replace(data.anchor.url)
      return
    }

    router.replace('/w/10/0/0')
  } catch (err) {
    console.error(err)
    router.replace('/w/10/0/0')
  }
}

onMounted(go)
</script>
