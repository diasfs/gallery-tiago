import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from './components/ui/dialog'

describe('admin theme tokens', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('teleports dialog content into the admin portal root', async () => {
    document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'

    const wrapper = mount({
      components: { Dialog, DialogContent, DialogDescription, DialogTitle },
      template: `
        <Dialog :open="true">
          <DialogContent>
            <DialogTitle>Portal content</DialogTitle>
            <DialogDescription>Admin-themed dialog</DialogDescription>
          </DialogContent>
        </Dialog>
      `,
    })
    await nextTick()

    const portalRoot = document.querySelector('#admin-portal-root')
    const content = portalRoot?.querySelector('[data-slot="dialog-content"]')
    expect(content).not.toBeNull()
    expect(content?.textContent).toContain('Portal content')

    wrapper.unmount()
  })
})
