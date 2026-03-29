import { createRouter, createWebHistory } from 'vue-router'
import WorldMap from './components/WorldMap.vue'
import SubscribeView from './components/SubscribeView.vue'
<<<<<<< HEAD
=======
import NewsletterRedirect from './components/NewsletterRedirect.vue'
>>>>>>> rescue/mobile-cover-flicker

export const router = createRouter({
  history: createWebHistory('/'),
  routes: [
<<<<<<< HEAD
    { path: '/', name: 'home', component: SubscribeView },
    { path: '/subscribe', name: 'subscribe', component: SubscribeView },
    { path: '/w/:z(\\d+)/:x(\\d+)/:y(\\d+)', name: 'tile', component: WorldMap },
    { path: '/:bundle/:slug', name: 'object', component: WorldMap },
    { path: '/:pathMatch(.*)*', redirect: '/' },
=======
    { path: '/', redirect: '/w/10/0/0' },

    { path: '/subscribe', name: 'subscribe', component: SubscribeView },

    { path: '/newsletter/:key', name: 'newsletter', component: NewsletterRedirect },
    {
      path: '/newsletter/:key/:date(\\d{4}-\\d{2}-\\d{2})',
      name: 'newsletter-date',
      component: NewsletterRedirect,
    },

    { path: '/w/:z(\\d+)/:x(\\d+)/:y(\\d+)', name: 'tile', component: WorldMap },

    { path: '/:bundle/:slug', name: 'object', component: WorldMap },

    { path: '/:pathMatch(.*)*', redirect: '/w/10/0/0' },
>>>>>>> rescue/mobile-cover-flicker
  ],
})
