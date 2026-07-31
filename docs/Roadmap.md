# Gallery v4 — Roadmap

Sugestões de features alinhadas ao estado atual do projeto (álbuns hierárquicos, faces/tags automáticos, busca pública, mapa, view counts, import v3).

## Em andamento / próximo

| Feature | Status |
|---------|--------|
| Slideshow / lightbox | Feito |
| Open Graph / preview social | Feito (HTML para crawlers via API + proxy) |
| Linha do tempo por data | Feito |

## Alto impacto, pouco código

- **Feed RSS/Atom** — endpoint de álbuns/fotos recentes em XML; útil para assinantes.
- **Mapa por foto** — pins individuais com GPS (hoje o mapa agrupa por álbum/local).
- **Índice público de pessoas** — página `/people` com busca (`searchNamedPublic` já existe na API).

## Aproveita pgvector / IA

| Feature | Status |
|---------|--------|
| Fotos parecidas | Feito |
| Sugestão de merge de pessoas | Feito |
| Busca por rosto (admin) | Feito |

## Admin / operação

- **Bulk: mover, retag, reprocess** — seleção múltipla em `AlbumPhotosView`.
- **Detecção de duplicata no upload** — hash perceptual no worker convert.
- **Dashboard de storage** — tamanho por álbum, originals vs AVIF, fila de processing.
- **Relatório tags sugeridas vs aceitas** — medir utilidade RAM++/MobileCLIP.

## Descoberta pública

- **“Neste dia” / memórias** — fotos com `taken_at` no dia/mês atual em anos anteriores.
- **Mais vistos** — ranking por `viewCount` (30d ou all-time).

## Compartilhamento / privacidade

- **Senha em álbum unlisted** — slug + senha sem conta de visitante.
- **Link com expiração** — token temporário para álbuns unlisted.
- **Ocultar pessoa** — flag `is_public`; some da busca pública, permanece no admin.

## Spec original, ainda pendente

- Full-text em título/descrição de álbum/foto.
- Política de purge de originals com UI.
- PWA / cache offline de thumbs.

## YAGNI — só se pedir explícito

- Contas de visitante / favoritos na nuvem
- Comentários
- App mobile nativo
- Watermark automático
- Export de site estático
