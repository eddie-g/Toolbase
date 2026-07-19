<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\PdfAcroForm;
use App\Models\PdfGroup;
use App\Models\PdfState;

class PdfDocumentPageOffsetRemapper
{
    private const PAGE_KEYS = [
        'pageIndex',
        'page_index',
        'pageNumber',
        'page_number',
        'page_num',
        'page',
        'db_page_number',
        'promotedSourcePage',
    ];

    public function remap(Document $document, int $offset): void
    {
        if ($offset === 0) {
            return;
        }

        PdfState::query()
            ->where('document_id', $document->id)
            ->eachById(function (PdfState $state) use ($document, $offset): void {
                if ($state->page_number !== null) {
                    $state->page_number = max(0, (int) $state->page_number + $offset);
                }
                $state->annotation_data = $this->remapValue($state->annotation_data, $offset, (int) $document->id);
                if (is_array($state->annotation_debug)) {
                    $state->annotation_debug = $this->remapValue($state->annotation_debug, $offset, (int) $document->id);
                }
                $state->save();
            });

        PdfAcroForm::query()
            ->where('document_id', $document->id)
            ->eachById(function (PdfAcroForm $form) use ($document, $offset): void {
                if ($form->page_num !== null) {
                    $form->page_num = max(0, (int) $form->page_num + $offset);
                }
                $form->data = $this->remapValue($form->data, $offset, (int) $document->id);
                $form->save();
            });

        DocumentNote::query()
            ->where('document_id', $document->id)
            ->whereNotNull('page_index')
            ->increment('page_index', $offset);

        PdfGroup::query()
            ->where('document_id', $document->id)
            ->eachById(function (PdfGroup $group) use ($document, $offset): void {
                if ($group->page_number !== null) {
                    $group->page_number = max(0, (int) $group->page_number + $offset);
                }
                $group->group_key = $this->remapString((string) $group->group_key, $offset, (int) $document->id);
                if ($group->root_source_key !== null) {
                    $group->root_source_key = $this->remapString((string) $group->root_source_key, $offset, (int) $document->id);
                }
                foreach (['annotation_ids', 'annotation_source_keys', 'group_data'] as $field) {
                    if (is_array($group->{$field})) {
                        $group->{$field} = $this->remapValue($group->{$field}, $offset, (int) $document->id);
                    }
                }
                $group->save();
            });
    }

    public function remapPayload(array $payload, int $offset, int $documentId): array
    {
        return $this->remapValue($payload, $offset, $documentId);
    }

    private function remapValue(mixed $value, int $offset, int $documentId, ?string $key = null): mixed
    {
        if (in_array($key, self::PAGE_KEYS, true) && is_numeric($value)) {
            return max(0, (int) $value + $offset);
        }

        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $value[$childKey] = $this->remapValue(
                    $childValue,
                    $offset,
                    $documentId,
                    is_string($childKey) ? $childKey : null,
                );
            }

            return $value;
        }

        if (is_string($value)) {
            return $this->remapString($value, $offset, $documentId);
        }

        return $value;
    }

    private function remapString(string $value, int $offset, int $documentId): string
    {
        $value = preg_replace_callback(
            '/\bblock-(\d+)-(\d+)/',
            static fn (array $matches): string => 'block-' . ((int) $matches[1] + $offset) . '-' . $matches[2],
            $value,
        ) ?? $value;

        $value = preg_replace_callback(
            '/\bpromoted_(\d+)_/',
            static fn (array $matches): string => 'promoted_' . ((int) $matches[1] + $offset) . '_',
            $value,
        ) ?? $value;

        $quotedDocumentId = preg_quote((string) $documentId, '/');
        $value = preg_replace_callback(
            '/\bpdfjs_' . $quotedDocumentId . '_(\d+)_/',
            static fn (array $matches): string => 'pdfjs_' . $documentId . '_' . ((int) $matches[1] + $offset) . '_',
            $value,
        ) ?? $value;

        return preg_replace_callback(
            '/source:(\d+):/',
            static fn (array $matches): string => 'source:' . ((int) $matches[1] + $offset) . ':',
            $value,
        ) ?? $value;
    }
}
