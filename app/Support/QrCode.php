<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR-kodni serverda SVG qilib yaratadi (tashqi servis yo'q). SVG sahifaga inline qo'yiladi va
 * o'lchami CSS bilan beriladi (width/height atributlari olib tashlanadi, viewBox qoladi).
 */
final class QrCode
{
    /** @var array<string, string> */
    private static array $memo = [];

    /**
     * @param  int  $margin  atrofdagi bo'sh maydon, modul hisobida (skaner o'qishi uchun)
     */
    public static function svg(string $data, int $margin = 2): string
    {
        $key = $margin.'|'.$data;

        return self::$memo[$key] ??= self::render($data, $margin);
    }

    private static function render(string $data, int $margin): string
    {
        $renderer = new ImageRenderer(new RendererStyle(256, $margin), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($data, 'UTF-8', ErrorCorrectionLevel::M());

        // XML sarlavhasi inline SVG uchun keraksiz; o'lcham CSS'dan keladi.
        $svg = (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
        $svg = (string) preg_replace('/(<svg\b[^>]*?)\s+width="[^"]*"\s+height="[^"]*"/', '$1', $svg, 1);

        return trim(str_replace('<svg ', '<svg shape-rendering="crispEdges" ', $svg));
    }
}
