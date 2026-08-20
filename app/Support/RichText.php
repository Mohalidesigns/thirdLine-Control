<?php

namespace App\Support;

/**
 * Server-side handling for Editor.js documents.
 *
 * A rich field is stored twice: the Editor.js JSON in `{field}_rich`
 * (cast to array) and a rendered plain-text mirror in the original
 * `{field}` column, so search, filters, exports and PDF/report generation
 * keep reading exactly the column they always read.
 *
 * Images and file attachments are deliberately NOT supported: every file
 * in this product enters through the evidence pipeline (classification,
 * personal-data flags, retention) and a picture pasted into a narrative
 * would bypass all of it.
 */
class RichText
{
    /** Inline tags Editor.js produces that we keep; everything else is stripped. */
    private const INLINE_TAGS = '<b><i><strong><em><a><mark><code><u><s><br>';

    /** Block types allowed per toolset preset. */
    public const PRESETS = [
        'minimal' => ['paragraph', 'list'],
        'default' => ['paragraph', 'list', 'header', 'checklist', 'quote', 'table', 'linkTool'],
        'full' => ['paragraph', 'list', 'header', 'checklist', 'quote', 'table', 'linkTool', 'code', 'delimiter'],
    ];

    /** Does the value look like an Editor.js document? */
    public static function isDoc(mixed $value): bool
    {
        return is_array($value) && array_key_exists('blocks', $value) && is_array($value['blocks']);
    }

    /** Wrap legacy plain text (or HTML-ish text) into a paragraph document. */
    public static function fromPlain(?string $text): array
    {
        $paragraphs = collect(preg_split('/\r?\n/', (string) $text))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->map(fn (string $line) => [
                'type' => 'paragraph',
                'data' => ['text' => e($line)],
            ])
            ->values()
            ->all();

        return ['time' => 0, 'version' => 'plain-migrated', 'blocks' => $paragraphs];
    }

    /** Render a document to the plain-text mirror used by search and exports. */
    public static function toPlain(?array $doc): string
    {
        if (! self::isDoc($doc)) {
            return '';
        }

        $lines = [];
        foreach ($doc['blocks'] as $block) {
            $data = $block['data'] ?? [];
            $lines[] = match ($block['type'] ?? '') {
                'paragraph', 'header' => self::inlineToPlain($data['text'] ?? ''),
                'quote' => trim(self::inlineToPlain($data['text'] ?? '').(isset($data['caption']) && $data['caption'] !== '' ? ' — '.self::inlineToPlain($data['caption']) : '')),
                'list' => self::listToPlain($data['items'] ?? []),
                'checklist' => collect($data['items'] ?? [])
                    ->map(fn ($item) => (($item['checked'] ?? false) ? '[x] ' : '[ ] ').self::inlineToPlain($item['text'] ?? ''))
                    ->implode("\n"),
                'table' => collect($data['content'] ?? [])
                    ->map(fn ($row) => collect($row)->map(fn ($cell) => self::inlineToPlain((string) $cell))->implode(' | '))
                    ->implode("\n"),
                'code' => (string) ($data['code'] ?? ''),
                'linkTool' => (string) ($data['link'] ?? ''),
                'delimiter' => '⁂',
                default => '',
            };
        }

        return trim(implode("\n", array_filter($lines, fn ($line) => $line !== '')));
    }

    /**
     * Sanitise a document in place: keep only the preset's block types
     * (an unknown type is the caller's error, handled by the validation
     * rule before this runs), strip inline HTML down to the allowlist,
     * and drop unsafe link protocols.
     */
    public static function sanitize(array $doc, string $preset = 'default'): array
    {
        $allowed = self::PRESETS[$preset] ?? self::PRESETS['default'];

        $blocks = [];
        foreach ($doc['blocks'] ?? [] as $block) {
            $type = $block['type'] ?? '';
            if (! in_array($type, $allowed, true)) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $blocks[] = ['type' => $type, 'data' => match ($type) {
                'paragraph' => ['text' => self::cleanInline($data['text'] ?? '')],
                'header' => [
                    'text' => self::cleanInline($data['text'] ?? ''),
                    'level' => min(6, max(1, (int) ($data['level'] ?? 2))),
                ],
                'quote' => [
                    'text' => self::cleanInline($data['text'] ?? ''),
                    'caption' => self::cleanInline($data['caption'] ?? ''),
                    'alignment' => in_array($data['alignment'] ?? '', ['left', 'center'], true) ? $data['alignment'] : 'left',
                ],
                'list' => [
                    'style' => in_array($data['style'] ?? '', ['ordered', 'unordered', 'checklist'], true) ? $data['style'] : 'unordered',
                    'items' => self::cleanListItems($data['items'] ?? []),
                ],
                'checklist' => ['items' => collect($data['items'] ?? [])->map(fn ($item) => [
                    'text' => self::cleanInline(is_array($item) ? ($item['text'] ?? '') : (string) $item),
                    'checked' => (bool) (is_array($item) ? ($item['checked'] ?? false) : false),
                ])->values()->all()],
                'table' => [
                    'withHeadings' => (bool) ($data['withHeadings'] ?? false),
                    'content' => collect($data['content'] ?? [])->map(fn ($row) => collect(is_array($row) ? $row : [])
                        ->map(fn ($cell) => self::cleanInline((string) $cell))->values()->all())->values()->all(),
                ],
                'code' => ['code' => (string) ($data['code'] ?? '')],
                'linkTool' => [
                    'link' => self::cleanUrl((string) ($data['link'] ?? '')),
                    'meta' => [
                        'title' => strip_tags((string) ($data['meta']['title'] ?? '')),
                        'description' => strip_tags((string) ($data['meta']['description'] ?? '')),
                    ],
                ],
                'delimiter' => [],
                default => [],
            }];
        }

        return [
            'time' => (int) ($doc['time'] ?? 0),
            'version' => substr((string) ($doc['version'] ?? ''), 0, 20),
            'blocks' => $blocks,
        ];
    }

    /** Keep allowlisted inline tags, drop every attribute except a safe href. */
    private static function cleanInline(string $html): string
    {
        $html = strip_tags($html, self::INLINE_TAGS);

        // Strip attributes from every kept tag; <a> keeps only a safe href.
        return (string) preg_replace_callback(
            '/<(a)\s+[^>]*>|<(b|i|strong|em|mark|code|u|s)\s+[^>]*>/i',
            function (array $match) {
                if (strtolower($match[1] ?? '') === 'a') {
                    preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $match[0], $href);
                    $url = self::cleanUrl(html_entity_decode($href[2] ?? ''));

                    return $url === ''
                        ? '<a>'
                        : '<a href="'.e($url).'" rel="noopener noreferrer" target="_blank">';
                }

                return '<'.strtolower($match[2]).'>';
            },
            $html,
        );
    }

    private static function cleanUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('/^(https?:\/\/|mailto:|\/)/i', $url) ? $url : '';
    }

    /** Editor.js list items are strings (v1) or {content, items} trees (v2). */
    private static function cleanListItems(array $items, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }

        return collect($items)->map(function ($item) use ($depth) {
            if (is_array($item)) {
                return [
                    'content' => self::cleanInline((string) ($item['content'] ?? '')),
                    'meta' => ['checked' => (bool) ($item['meta']['checked'] ?? false)],
                    'items' => self::cleanListItems(is_array($item['items'] ?? null) ? $item['items'] : [], $depth + 1),
                ];
            }

            return ['content' => self::cleanInline((string) $item), 'meta' => [], 'items' => []];
        })->values()->all();
    }

    private static function listToPlain(array $items, int $depth = 0): string
    {
        return collect($items)->map(function ($item) use ($depth) {
            $prefix = str_repeat('  ', $depth).'- ';
            if (is_array($item)) {
                $line = $prefix.self::inlineToPlain((string) ($item['content'] ?? ''));
                $children = self::listToPlain(is_array($item['items'] ?? null) ? $item['items'] : [], $depth + 1);

                return $children === '' ? $line : $line."\n".$children;
            }

            return $prefix.self::inlineToPlain((string) $item);
        })->implode("\n");
    }

    private static function inlineToPlain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
