import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('../views/HomeView.vue'),
    },
    {
      path: '/albums/:slug',
      name: 'album',
      component: () => import('../views/AlbumView.vue'),
      props: true,
    },
    {
      path: '/photos/:id',
      name: 'photo',
      component: () => import('../views/PhotoView.vue'),
      props: true,
    },
    {
      path: '/people/:id',
      name: 'person',
      component: () => import('../views/PersonView.vue'),
      props: true,
    },
    {
      path: '/tags/:slug',
      name: 'tag',
      component: () => import('../views/TagView.vue'),
      props: true,
    },
    {
      path: '/locations/:id',
      name: 'location',
      component: () => import('../views/LocationView.vue'),
      props: true,
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('../views/NotFoundView.vue'),
    },
  ],
})

export default router
