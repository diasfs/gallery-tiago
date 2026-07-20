import { createRouter, createWebHistory } from 'vue-router'
import { adminApi } from '../api/client'

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
      path: '/admin/login',
      name: 'admin-login',
      component: () => import('../views/admin/LoginView.vue'),
      meta: { adminPublic: true },
    },
    {
      path: '/admin',
      component: () => import('../components/admin/AdminLayout.vue'),
      meta: { admin: true },
      children: [
        {
          path: '',
          name: 'admin-albums',
          component: () => import('../views/admin/AlbumsView.vue'),
        },
        {
          path: 'albums/:albumId/photos',
          name: 'admin-album-photos',
          component: () => import('../views/admin/AlbumPhotosView.vue'),
          props: true,
        },
        {
          path: 'photos/:id',
          name: 'admin-photo-edit',
          component: () => import('../views/admin/PhotoEditView.vue'),
          props: true,
        },
        {
          path: 'people/unnamed',
          name: 'admin-unnamed-people',
          component: () => import('../views/admin/UnnamedPeopleView.vue'),
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('../views/NotFoundView.vue'),
    },
  ],
})

/**
 * Every `/admin/*` route except the login page requires a live admin
 * session; we probe `GET /api/admin/me` (cookie-based) rather than trusting
 * any client-side flag, since the cookie is the actual source of truth.
 */
router.beforeEach(async (to) => {
  const needsAdmin = to.matched.some((record) => record.meta.admin)
  if (!needsAdmin) {
    return true
  }

  try {
    await adminApi.me()
    return true
  } catch {
    return { name: 'admin-login', query: { redirect: to.fullPath } }
  }
})

export default router
