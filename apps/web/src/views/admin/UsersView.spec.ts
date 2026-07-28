import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UsersView from './UsersView.vue'
import { adminApi } from '../../api/client'
import type { AdminUser } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      listUsers: vi.fn(),
      me: vi.fn(),
      createUser: vi.fn(),
      updateUser: vi.fn(),
      deleteUser: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listUsers: ReturnType<typeof vi.fn>
  me: ReturnType<typeof vi.fn>
  createUser: ReturnType<typeof vi.fn>
  updateUser: ReturnType<typeof vi.fn>
  deleteUser: ReturnType<typeof vi.fn>
}

const owner: AdminUser = {
  id: 'user-1',
  email: 'owner@gallery.test',
  roles: ['ROLE_ADMIN'],
}

const other: AdminUser = {
  id: 'user-2',
  email: 'other@gallery.test',
  roles: ['ROLE_ADMIN'],
}

describe('UsersView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listUsers.mockResolvedValue([owner, other])
    mockedApi.me.mockResolvedValue(owner)
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  async function mountView() {
    document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
    const wrapper = mount(UsersView, { attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  it('lists users and disables delete for the current account', async () => {
    const wrapper = await mountView()

    const rows = wrapper.findAll('[data-testid="user-row"]')
    expect(rows).toHaveLength(2)
    expect(wrapper.text()).toContain('owner@gallery.test')
    expect(wrapper.text()).toContain('(você)')

    const deleteButtons = wrapper.findAll('[data-testid="user-delete"]')
    expect(deleteButtons[0].attributes('disabled')).toBeDefined()
    expect(deleteButtons[1].attributes('disabled')).toBeUndefined()

    wrapper.unmount()
  })

  it('deletes another admin after confirm', async () => {
    mockedApi.deleteUser.mockResolvedValue(undefined)
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    const wrapper = await mountView()
    const deleteButtons = wrapper.findAll('[data-testid="user-delete"]')
    await deleteButtons[1].trigger('click')
    await flushPromises()

    expect(mockedApi.deleteUser).toHaveBeenCalledWith('user-2')
    expect(wrapper.text()).not.toContain('other@gallery.test')

    wrapper.unmount()
  })

  it('creates a user from the dialog', async () => {
    mockedApi.createUser.mockResolvedValue({
      id: 'user-3',
      email: 'new@gallery.test',
      roles: ['ROLE_ADMIN'],
    })

    const wrapper = await mountView()
    await wrapper.find('[data-testid="users-new"]').trigger('click')
    await flushPromises()

    const { DOMWrapper } = await import('@vue/test-utils')
    const email = new DOMWrapper(document.querySelector('[data-testid="user-email"]')!)
    const password = new DOMWrapper(document.querySelector('[data-testid="user-password"]')!)
    await email.setValue('new@gallery.test')
    await password.setValue('password123')
    await flushPromises()

    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    )
    await flushPromises()

    expect(mockedApi.createUser).toHaveBeenCalledWith({
      email: 'new@gallery.test',
      password: 'password123',
    })
    expect(wrapper.text()).toContain('new@gallery.test')

    wrapper.unmount()
  })
})
