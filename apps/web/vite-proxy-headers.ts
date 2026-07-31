import type { ProxyOptions } from 'vite'

/** Forward the browser-facing host/proto so Symfony can build public absolute URLs. */
export function withPublicHostHeaders(proxyOptions: ProxyOptions): ProxyOptions {
  return {
    ...proxyOptions,
    configure: (proxy, options) => {
      proxy.on('proxyReq', (proxyReq, req) => {
        const host = req.headers['x-forwarded-host'] ?? req.headers.host
        const proto =
          req.headers['x-forwarded-proto'] ??
          ((req.socket as { encrypted?: boolean }).encrypted ? 'https' : 'http')

        if (typeof host === 'string' && host !== '') {
          proxyReq.setHeader('X-Forwarded-Host', host)
        }
        if (typeof proto === 'string' && proto !== '') {
          proxyReq.setHeader('X-Forwarded-Proto', proto)
        }
      })
      proxyOptions.configure?.(proxy, options)
    },
  }
}
