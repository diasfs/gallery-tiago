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
      path: '/search',
      name: 'search',
      component: () => import('../views/SearchView.vue'),
    },
    {
      path: '/map',
      name: 'map',
      component: () => import('../views/MapView.vue'),
    },
    {
      path: '/timeline',
      name: 'timeline',
      component: () => import('../views/TimelineView.vue'),
    },
    {
      path: '/timeline/:year/:month',
      name: 'timeline-month',
      component: () => import('../views/TimelineView.vue'),
      props: true,
    },
    {
      path: '/memories',
      name: 'memories',
      component: () => import('../views/MemoriesView.vue'),
    },
    {
      path: '/popular',
      name: 'popular',
      component: () => import('../views/PopularView.vue'),
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
      path: '/tags',
      name: 'tags',
      component: () => import('../views/TagsIndexView.vue'),
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
          path: 'people',
          name: 'admin-people',
          component: () => import('../views/admin/PeopleView.vue'),
        },
        {
          path: 'people/unnamed',
          redirect: { name: 'admin-people', query: { scope: 'unnamed' } },
        },
        {
          path: 'people/:id',
          name: 'admin-person-edit',
          component: () => import('../views/admin/PersonEditView.vue'),
          props: true,
        },
        {
          path: 'tags',
          name: 'admin-tags',
          component: () => import('../views/admin/TagsView.vue'),
        },
        {
          path: 'processing',
          name: 'admin-processing',
          component: () => import('../views/admin/ProcessingView.vue'),
        },
        {
          path: 'settings',
          name: 'admin-settings',
          component: () => import('../views/admin/SettingsView.vue'),
        },
        {
          path: 'users',
          name: 'admin-users',
          component: () => import('../views/admin/UsersView.vue'),
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
