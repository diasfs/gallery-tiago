<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

final class Pagination
{
    public static function page(Request $request, int $default = 1): int
    {
        return max(1, (int) $request->query->get('page', $default));
    }

    public static function perPage(Request $request, int $default, int $max = 100): int
    {
        return min($max, max(1, (int) $request->query->get('perPage', $default)));
    }

    /**
     * @return array{page: int, perPage: int, total: int}
     */
    public static function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ];
    }
}
