import { createRouter, createWebHistory } from 'vue-router'
import WorldMap from './components/WorldMap.vue'
import SubscribeView from './components/SubscribeView.vue'

export const router = createRouter({
  history: createWebHistory('/'),
  routes: [
    { path: '/', name: 'home', component: SubscribeView },
    { path: '/subscribe', name: 'subscribe', component: SubscribeView },
    { path: '/w/:z(\\d+)/:x(\\d+)/:y(\\d+)', name: 'tile', component: WorldMap },
    { path: '/:bundle/:slug', name: 'object', component: WorldMap },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})
