import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const adminCss = readFileSync(path.resolve(process.cwd(), 'src/admin.css'), 'utf8')

describe('admin theme tokens', () => {
  it('exposes tokens to admin content portalled under body', () => {
    expect(adminCss).toMatch(/:root,\s*\.admin-root\s*\{/)
    expect(adminCss).toMatch(/--background:\s*#0c0c0e/)
    expect(adminCss).toMatch(/--popover:\s*#16161a/)
    expect(adminCss).toMatch(/--ring:\s*#7c9cff/)
  })
})
