<?php

namespace App\Service;

final class SocialCrawlerDetector
{
    private const PATTERN = '/'
        .'facebookexternalhit|Facebot|Twitterbot|LinkedInBot|WhatsApp|TelegramBot|'
        .'Slackbot|Discordbot|Applebot|Pinterest|Googlebot|bingbot'
        .'/i';

    public static function isSocialCrawler(?string $userAgent): bool
    {
        if (null === $userAgent || '' === $userAgent) {
            return false;
        }

        return 1 === preg_match(self::PATTERN, $userAgent);
    }
}
