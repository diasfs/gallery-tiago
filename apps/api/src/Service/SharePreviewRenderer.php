<?php

namespace App\Service;

final class SharePreviewRenderer
{
    public function render(SharePreview $preview): string
    {
        $title = $this->escape($preview->title);
        $description = $this->escape($preview->description);
        $canonicalUrl = $this->escape($preview->canonicalUrl);
        $imageTag = null !== $preview->imageUrl
            ? $this->renderImageTags($preview)
            : '';

        return <<<HTML
            <!doctype html>
            <html lang="pt-BR">
              <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>{$title}</title>
                <meta name="description" content="{$description}" />
                <link rel="canonical" href="{$canonicalUrl}" />
                <meta property="og:title" content="{$title}" />
                <meta property="og:description" content="{$description}" />
                <meta property="og:type" content="website" />
                <meta property="og:url" content="{$canonicalUrl}" />
                <meta name="twitter:card" content="summary_large_image" />
                <meta name="twitter:title" content="{$title}" />
                <meta name="twitter:description" content="{$description}" />
                {$imageTag}
              </head>
              <body>
                <p><a href="{$canonicalUrl}">{$title}</a></p>
              </body>
            </html>
            HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function renderImageTags(SharePreview $preview): string
    {
        $url = $this->escape($preview->imageUrl ?? '');
        $tags = [
            sprintf('<meta property="og:image" content="%s" />', $url),
            sprintf('<meta name="twitter:image" content="%s" />', $url),
        ];

        if (null !== $preview->imageType) {
            $tags[] = sprintf('<meta property="og:image:type" content="%s" />', $this->escape($preview->imageType));
        }
        if (null !== $preview->imageWidth) {
            $tags[] = sprintf('<meta property="og:image:width" content="%d" />', $preview->imageWidth);
        }
        if (null !== $preview->imageHeight) {
            $tags[] = sprintf('<meta property="og:image:height" content="%d" />', $preview->imageHeight);
        }

        return implode("\n    ", $tags);
    }
}
