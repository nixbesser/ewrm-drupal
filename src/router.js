import { createRouter, createWebHistory } from 'vue-router'
import WorldMap from './components/WorldMap.vue'
import SubscribeView from './components/SubscribeView.vue'

export const router = createRouter({
  history: createWebHistory('/world/'),
  routes: [
    { path: '/', redirect: '/w/10/0/0' },
    { path: '/subscribe', name: 'subscribe', component: SubscribeView },
    { path: '/w/:z(\\d+)/:x(\\d+)/:y(\\d+)', name: 'tile', component: WorldMap },
    { path: '/:bundle/:slug', name: 'object', component: WorldMap },
    { path: '/:pathMatch(.*)*', redirect: '/w/10/0/0' },
  ],
})
