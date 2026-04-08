<?php

namespace App\Services;

use App\Models\PdfExtractionBlock;
use App\Models\PdfExtractionFitz;
use App\Models\PdfExtractionPage;
use App\Models\PdfExtractionSpan;
use Illuminate\Support\Facades\DB;

class PdfFitzExtractionNormalizer
{
    public function snapshotId(mixed $extractionRow): ?int
    {
        $value = $this->readValue($extractionRow, 'id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function hasNormalizedSnapshot(int $snapshotId): bool
    {
        return PdfExtractionPage::query()
            ->where('pdf_extraction_fitz_id', $snapshotId)
            ->exists();
    }

    public function syncSnapshot(mixed $extractionRow): ?int
    {
        $snapshotId = $this->snapshotId($extractionRow);
        $documentId = $this->documentId($extractionRow);
        $pages = $this->decodeExtractionPages($extractionRow);

        if ($snapshotId === null || $documentId === null || !is_array($pages)) {
            return null;
        }

        DB::transaction(function () use ($snapshotId, $documentId, $pages) {
            PdfExtractionPage::query()
                ->where('pdf_extraction_fitz_id', $snapshotId)
                ->delete();

            foreach ($pages as $page) {
                if (!is_array($page)) {
                    continue;
                }

                $pageNumber = is_numeric($page['page_number'] ?? null) ? (int) $page['page_number'] : null;
                if ($pageNumber === null || $pageNumber <= 0) {
                    continue;
                }

                $pageModel = PdfExtractionPage::query()->create([
                    'pdf_extraction_fitz_id' => $snapshotId,
                    'document_id' => $documentId,
                    'page_number' => $pageNumber,
                    'width' => $this->toFloat($page['width'] ?? 0),
                    'height' => $this->toFloat($page['height'] ?? 0),
                    'word_count' => $this->toInt($page['word_count'] ?? 0),
                    'text' => $this->toNullableString($page['text'] ?? null),
                    'drawn_box_rects' => $this->normalizeRectList($page['drawn_box_rects'] ?? null),
                    'widget_rects' => $this->normalizeRectList($page['widget_rects'] ?? null),
                ]);

                $pageLinesByBlock = [];
                foreach ($this->arrayValue($page['lines'] ?? null) as $line) {
                    if (!is_array($line)) {
                        continue;
                    }

                    $blockNum = is_numeric($line['block_num'] ?? null) ? (int) $line['block_num'] : null;
                    if ($blockNum === null) {
                        continue;
                    }

                    $pageLinesByBlock[$blockNum][] = $line;
                }

                foreach ($this->arrayValue($page['blocks'] ?? null) as $block) {
                    if (!is_array($block)) {
                        continue;
                    }

                    $blockNum = is_numeric($block['block_num'] ?? null) ? (int) $block['block_num'] : null;
                    if ($blockNum === null) {
                        continue;
                    }

                    $rootSourceKey = 'block-' . $pageNumber . '-' . $blockNum;
                    $blockData = $block;
                    unset($blockData['spans']);

                    $blockModel = PdfExtractionBlock::query()->create([
                        'page_id' => $pageModel->id,
                        'pdf_extraction_fitz_id' => $snapshotId,
                        'document_id' => $documentId,
                        'page_number' => $pageNumber,
                        'block_num' => $blockNum,
                        'source_key' => $rootSourceKey,
                        'root_source_key' => $rootSourceKey,
                        'text' => $this->toNullableString($block['text'] ?? null),
                        'text_single_line' => $this->toNullableString($block['text_single_line'] ?? null),
                        'text_lines' => $this->normalizeStringList($block['text_lines'] ?? null),
                        'line_bboxes' => $this->normalizeRectList($block['line_bboxes'] ?? null),
                        'left' => $this->toFloat($block['left'] ?? 0),
                        'top' => $this->toFloat($block['top'] ?? 0),
                        'width' => $this->toFloat($block['width'] ?? 0),
                        'height' => $this->toFloat($block['height'] ?? 0),
                        'bbox' => $this->normalizeRect($block['bbox'] ?? null),
                        'line_count' => $this->toInt($block['line_count'] ?? count($this->arrayValue($block['text_lines'] ?? null))),
                        'font' => $this->toNullableString($block['font'] ?? null),
                        'font_xref' => $this->toNullableInt($block['font_xref'] ?? null),
                        'font_size' => $this->toNullableFloat($block['font_size'] ?? null),
                        'font_weight' => $this->toNullableString($block['font_weight'] ?? null),
                        'italic' => $this->toBool($block['italic'] ?? false),
                        'underline' => $this->toBool($block['underline'] ?? false),
                        'hex_color' => $this->toNullableString($block['hex_color'] ?? null),
                        'line_height' => $this->toNullableFloat($block['line_height'] ?? null),
                        'avg_line_height' => $this->toNullableFloat($block['avg_line_height'] ?? null),
                        'rotation' => $this->toNullableFloat($block['rotation'] ?? null),
                        'direction' => $this->normalizePoint($block['direction'] ?? null),
                        'has_mixed_styles' => $this->toBool($block['has_mixed_styles'] ?? false),
                        'block_data' => $blockData,
                    ]);

                    $spanRows = [];
                    $spanIndex = 0;
                    $blockLines = $pageLinesByBlock[$blockNum] ?? [];
                    usort($blockLines, static function (array $left, array $right): int {
                        $leftLineNum = is_numeric($left['line_num'] ?? null) ? (int) $left['line_num'] : PHP_INT_MAX;
                        $rightLineNum = is_numeric($right['line_num'] ?? null) ? (int) $right['line_num'] : PHP_INT_MAX;
                        if ($leftLineNum !== $rightLineNum) {
                            return $leftLineNum <=> $rightLineNum;
                        }

                        return 0;
                    });

                    foreach ($blockLines as $line) {
                        $lineNum = is_numeric($line['line_num'] ?? null) ? (int) $line['line_num'] : null;
                        foreach ($this->arrayValue($line['spans'] ?? null) as $span) {
                            if (!is_array($span)) {
                                continue;
                            }
                            $spanRows[] = $this->buildSpanInsertRow(
                                $pageModel->id,
                                $blockModel->id,
                                $snapshotId,
                                $documentId,
                                $pageNumber,
                                $blockNum,
                                $lineNum,
                                $spanIndex++,
                                $span
                            );
                        }
                    }

                    if (empty($spanRows)) {
                        foreach ($this->arrayValue($block['spans'] ?? null) as $span) {
                            if (!is_array($span)) {
                                continue;
                            }
                            $spanRows[] = $this->buildSpanInsertRow(
                                $pageModel->id,
                                $blockModel->id,
                                $snapshotId,
                                $documentId,
                                $pageNumber,
                                $blockNum,
                                null,
                                $spanIndex++,
                                $span
                            );
                        }
                    }

                    foreach (array_chunk($spanRows, 250) as $chunk) {
                        PdfExtractionSpan::query()->insert($chunk);
                    }
                }
            }
        });

        return $snapshotId;
    }

    public function buildPageDataForSnapshot(int $snapshotId): array
    {
        $pages = PdfExtractionPage::query()
            ->where('pdf_extraction_fitz_id', $snapshotId)
            ->orderBy('page_number')
            ->get();
        if ($pages->isEmpty()) {
            return [];
        }

        $blocks = PdfExtractionBlock::query()
            ->where('pdf_extraction_fitz_id', $snapshotId)
            ->orderBy('page_number')
            ->orderBy('top')
            ->orderBy('left')
            ->orderBy('block_num')
            ->get();

        $spans = PdfExtractionSpan::query()
            ->where('pdf_extraction_fitz_id', $snapshotId)
            ->orderBy('page_number')
            ->orderBy('block_num')
            ->orderByRaw('CASE WHEN line_num IS NULL THEN 2147483647 ELSE line_num END')
            ->orderBy('span_index')
            ->get();

        $blocksByPage = [];
        foreach ($blocks as $block) {
            $blocksByPage[$block->page_number][] = $block;
        }

        $spansByBlock = [];
        foreach ($spans as $span) {
            $spansByBlock[$span->block_id][] = $span;
        }

        $payload = [];
        foreach ($pages as $page) {
            $pageBlocks = [];
            foreach ($blocksByPage[$page->page_number] ?? [] as $block) {
                $blockData = is_array($block->block_data) ? $block->block_data : [];
                $blockPayload = array_merge($blockData, [
                    'block_num' => (int) $block->block_num,
                    'text' => (string) ($block->text ?? ''),
                    'text_single_line' => (string) ($block->text_single_line ?? ''),
                    'text_lines' => is_array($block->text_lines) ? array_values($block->text_lines) : [],
                    'line_bboxes' => is_array($block->line_bboxes) ? array_values($block->line_bboxes) : [],
                    'left' => (float) $block->left,
                    'top' => (float) $block->top,
                    'width' => (float) $block->width,
                    'height' => (float) $block->height,
                    'bbox' => is_array($block->bbox)
                        ? array_values($block->bbox)
                        : [
                            (float) $block->left,
                            (float) $block->top,
                            (float) $block->left + (float) $block->width,
                            (float) $block->top + (float) $block->height,
                        ],
                    'line_count' => (int) $block->line_count,
                    'font' => $block->font,
                    'font_xref' => $block->font_xref,
                    'font_size' => $block->font_size !== null ? (float) $block->font_size : null,
                    'font_weight' => $block->font_weight,
                    'italic' => (bool) $block->italic,
                    'underline' => (bool) $block->underline,
                    'hex_color' => $block->hex_color,
                    'line_height' => $block->line_height !== null ? (float) $block->line_height : null,
                    'avg_line_height' => $block->avg_line_height !== null ? (float) $block->avg_line_height : null,
                    'rotation' => $block->rotation !== null ? (float) $block->rotation : null,
                    'direction' => is_array($block->direction) ? array_values($block->direction) : null,
                    'has_mixed_styles' => (bool) $block->has_mixed_styles,
                    'spans' => array_map(
                        fn (PdfExtractionSpan $span): array => $this->buildSpanPayload($span),
                        $spansByBlock[$block->id] ?? []
                    ),
                ]);
                $pageBlocks[] = $blockPayload;
            }

            $payload[] = [
                'page_number' => (int) $page->page_number,
                'width' => (float) $page->width,
                'height' => (float) $page->height,
                'blocks' => $pageBlocks,
                'lines' => [],
                'words' => [],
                'drawn_box_rects' => is_array($page->drawn_box_rects) ? array_values($page->drawn_box_rects) : [],
                'widget_rects' => is_array($page->widget_rects) ? array_values($page->widget_rects) : [],
                'text' => (string) ($page->text ?? ''),
                'word_count' => (int) $page->word_count,
            ];
        }

        return $payload;
    }

    private function buildSpanInsertRow(
        int $pageId,
        int $blockId,
        int $snapshotId,
        int $documentId,
        int $pageNumber,
        int $blockNum,
        ?int $lineNum,
        int $spanIndex,
        array $span
    ): array {
        $bbox = $this->normalizeRect($span['bbox'] ?? null);
        $left = $bbox[0] ?? 0.0;
        $top = $bbox[1] ?? 0.0;
        $right = $bbox[2] ?? $left;
        $bottom = $bbox[3] ?? $top;
        $now = now();

        return [
            'page_id' => $pageId,
            'block_id' => $blockId,
            'pdf_extraction_fitz_id' => $snapshotId,
            'document_id' => $documentId,
            'page_number' => $pageNumber,
            'block_num' => $blockNum,
            'line_num' => $lineNum,
            'span_index' => $spanIndex,
            'text' => $this->toNullableString($span['text'] ?? null),
            'render_text' => $this->toNullableString($span['render_text'] ?? null),
            'suppress_drawn_underline' => $this->toBool($span['suppress_drawn_underline'] ?? false),
            'has_drawn_underline' => $this->toBool($span['has_drawn_underline'] ?? false),
            'font' => $this->toNullableString($span['font'] ?? null),
            'font_xref' => $this->toNullableInt($span['font_xref'] ?? null),
            'font_size' => $this->toNullableFloat($span['font_size'] ?? null),
            'font_weight' => $this->toNullableString($span['font_weight'] ?? null),
            'color_value' => $this->toNullableString($span['color'] ?? null),
            'hex_color' => $this->toNullableString($span['hex_color'] ?? null),
            'bold' => $this->toBool($span['bold'] ?? false),
            'italic' => $this->toBool($span['italic'] ?? false),
            'flags' => $this->toNullableInt($span['flags'] ?? null),
            'left' => $left,
            'top' => $top,
            'width' => max(0.0, $right - $left),
            'height' => max(0.0, $bottom - $top),
            'bbox' => $this->encodeJsonColumn($bbox),
            'ascender' => $this->toNullableFloat($span['ascender'] ?? null),
            'descender' => $this->toNullableFloat($span['descender'] ?? null),
            'origin' => $this->encodeJsonColumn($this->normalizePoint($span['origin'] ?? null)),
            'direction' => $this->encodeJsonColumn($this->normalizePoint($span['direction'] ?? null)),
            'writing_mode' => $this->toInt($span['writing_mode'] ?? 0),
            'rotation' => $this->toNullableFloat($span['rotation'] ?? null),
            'line_width' => $this->toNullableFloat($span['line_width'] ?? null),
            'render_type' => $this->toNullableString($span['render_type'] ?? null),
            'space_width' => $this->toNullableFloat($span['space_width'] ?? null),
            'uses_embedded_font' => $this->toBool($span['uses_embedded_font'] ?? false),
            'embedded_font_name' => $this->toNullableString($span['embedded_font_name'] ?? null),
            'embedded_font_family' => $this->toNullableString($span['embedded_font_family'] ?? null),
            'embedded_font_xref' => $this->toNullableInt($span['embedded_font_xref'] ?? null),
            'is_link' => $this->toBool($span['is_link'] ?? false),
            'link_uri' => $this->toNullableString($span['link_uri'] ?? null),
            'link_kind' => $this->toNullableString($span['link_kind'] ?? null),
            'link_page' => $this->toNullableInt($span['link_page'] ?? null),
            'source_content_ops' => $this->encodeJsonColumn(
                is_array($span['source_content_ops'] ?? null) ? array_values($span['source_content_ops']) : null
            ),
            'span_data' => $this->encodeJsonColumn($span),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function buildSpanPayload(PdfExtractionSpan $span): array
    {
        $spanData = is_array($span->span_data) ? $span->span_data : [];

        return array_merge($spanData, [
            'text' => (string) ($span->text ?? ''),
            'render_text' => (string) ($span->render_text ?? ''),
            'suppress_drawn_underline' => (bool) $span->suppress_drawn_underline,
            'has_drawn_underline' => (bool) $span->has_drawn_underline,
            'font' => $span->font,
            'font_xref' => $span->font_xref,
            'font_size' => $span->font_size !== null ? (float) $span->font_size : null,
            'font_weight' => $span->font_weight,
            'color' => $span->color_value,
            'hex_color' => $span->hex_color,
            'bold' => (bool) $span->bold,
            'italic' => (bool) $span->italic,
            'flags' => $span->flags,
            'bbox' => is_array($span->bbox)
                ? array_values($span->bbox)
                : [
                    (float) $span->left,
                    (float) $span->top,
                    (float) $span->left + (float) $span->width,
                    (float) $span->top + (float) $span->height,
                ],
            'ascender' => $span->ascender !== null ? (float) $span->ascender : null,
            'descender' => $span->descender !== null ? (float) $span->descender : null,
            'origin' => is_array($span->origin) ? array_values($span->origin) : null,
            'direction' => is_array($span->direction) ? array_values($span->direction) : null,
            'writing_mode' => (int) $span->writing_mode,
            'rotation' => $span->rotation !== null ? (float) $span->rotation : null,
            'line_width' => $span->line_width !== null ? (float) $span->line_width : null,
            'render_type' => $span->render_type,
            'space_width' => $span->space_width !== null ? (float) $span->space_width : null,
            'uses_embedded_font' => (bool) $span->uses_embedded_font,
            'embedded_font_name' => $span->embedded_font_name,
            'embedded_font_family' => $span->embedded_font_family,
            'embedded_font_xref' => $span->embedded_font_xref,
            'is_link' => (bool) $span->is_link,
            'link_uri' => $span->link_uri,
            'link_kind' => $span->link_kind,
            'link_page' => $span->link_page,
            'source_content_ops' => is_array($span->source_content_ops) ? array_values($span->source_content_ops) : [],
        ]);
    }

    private function documentId(mixed $extractionRow): ?int
    {
        $value = $this->readValue($extractionRow, 'document_id');

        return is_numeric($value) ? (int) $value : null;
    }

    private function decodeExtractionPages(mixed $extractionRow): ?array
    {
        $value = $this->readValue($extractionRow, 'extraction_data');

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }

        if (($snapshotId = $this->snapshotId($extractionRow)) !== null) {
            $model = PdfExtractionFitz::query()->find($snapshotId);
            if ($model) {
                return is_array($model->extraction_data) ? $model->extraction_data : null;
            }
        }

        return null;
    }

    private function readValue(mixed $target, string $key): mixed
    {
        if (is_array($target)) {
            return $target[$key] ?? null;
        }

        if (is_object($target)) {
            return $target->{$key} ?? null;
        }

        return null;
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function normalizeRect(mixed $value): ?array
    {
        if (!is_array($value) || count($value) < 4) {
            return null;
        }

        return array_map(fn ($entry): float => $this->toFloat($entry), array_slice($value, 0, 4));
    }

    private function normalizeRectList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rects = [];
        foreach ($value as $entry) {
            $rect = $this->normalizeRect($entry);
            if ($rect !== null) {
                $rects[] = $rect;
            }
        }

        return $rects;
    }

    private function normalizePoint(mixed $value): ?array
    {
        if (!is_array($value) || count($value) < 2) {
            return null;
        }

        return [
            $this->toFloat($value[0] ?? 0),
            $this->toFloat($value[1] ?? 0),
        ];
    }

    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(
            fn ($entry): string => (string) ($entry ?? ''),
            $value
        ));
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private function encodeJsonColumn(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
