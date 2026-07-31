import type { Connect } from 'vite'

const CRAWLER_USER_AGENT =
  /facebookexternalhit|Facebot|Twitterbot|LinkedInBot|WhatsApp|TelegramBot|Slackbot|Discordbot|Applebot|Pinterest|Googlebot|bingbot/i

const RESERVED_ALBUM_SLUGS =
  'search|map|timeline|memories|popular|albums|photos|people|tags|locations|admin|api|converted|originals|faces|avatars'

const SHARE_PREVIEW_PATH = new RegExp(
  `^(?:` +
    `/photos/[0-9a-f-]{36}` +
    `|/albums/[^/?]+` +
    `|/(?!${RESERVED_ALBUM_SLUGS})[^/?]+/[^/?]+\\.[a-z0-9]{2,5}` +
    `|/(?!${RESERVED_ALBUM_SLUGS})[^/?]+` +
    `)/?$`,
  'i',
)

function headerValue(value: string | string[] | undefined): string | undefined {
  if (Array.isArray(value)) {
    return value[0]
  }

  return value
}

export function sharePreviewProxyMiddleware(apiTarget: string): Connect.NextHandleFunction {
  return (req, res, next) => {
    const path = (req.url ?? '').split('?')[0] ?? ''
    if (!SHARE_PREVIEW_PATH.test(path)) {
      next()
      return
    }

    const userAgent = req.headers['user-agent'] ?? ''
    if (!CRAWLER_USER_AGENT.test(userAgent)) {
      next()
      return
    }

    const target = `${apiTarget.replace(/\/$/, '')}${req.url}`
    const host = headerValue(req.headers['x-forwarded-host']) ?? headerValue(req.headers.host)
    const proto =
      headerValue(req.headers['x-forwarded-proto']) ??
      ((req.socket as { encrypted?: boolean }).encrypted ? 'https' : 'http')

    const headers: Record<string, string> = {
      'User-Agent': userAgent,
      Accept: 'text/html',
    }
    if (host) {
      headers['X-Forwarded-Host'] = host
    }
    if (proto) {
      headers['X-Forwarded-Proto'] = proto
    }

    void fetch(target, {
      headers,
      redirect: 'manual',
    })
      .then(async (response) => {
        res.statusCode = response.status
        response.headers.forEach((value, key) => {
          if (key.toLowerCase() === 'transfer-encoding') return
          res.setHeader(key, value)
        })
        const body = Buffer.from(await response.arrayBuffer())
        res.end(body)
      })
      .catch(() => next())
  }
}

export { SHARE_PREVIEW_PATH }
