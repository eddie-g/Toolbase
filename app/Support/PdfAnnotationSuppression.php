<?php

namespace App\Support;

class PdfAnnotationSuppression
{
    /**
     * Return annotation IDs that should be suppressed to avoid double-rendering.
     *
     * @param  array<int, string>  $annotationIds
     * @return array<int, string>
     */
    public static function suppressedIds(array $annotationIds): array
    {
        $annotationIds = array_values(array_unique(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            $annotationIds
        ))));

        $idLookup = array_fill_keys($annotationIds, true);
        $suppressed = [];

        $extractSimpleLineRange = static function (string $annId): ?array {
            if (!preg_match('/^(.+?)_lines-(\d+)-(\d+)$/', $annId, $m)) {
                return null;
            }

            return [
                'base' => $m[1],
                'start' => (int) $m[2],
                'end' => (int) $m[3],
            ];
        };

        $extractModifierLineRange = static function (string $annId, string $base): ?array {
            if (!preg_match('/^' . preg_quote($base, '/') . '_.+-lines-(?:\d+-)?(\d+)-(\d+)$/', $annId, $m)) {
                return null;
            }

            return [
                'start' => (int) $m[1],
                'end' => (int) $m[2],
            ];
        };

        $hasDescendantVariant = static function (string $baseId, string $currentId) use ($annotationIds): bool {
            $prefix = $baseId . '_';
            foreach ($annotationIds as $candidateId) {
                if ($candidateId === $currentId) {
                    continue;
                }

                if (!str_starts_with($candidateId, $prefix)) {
                    continue;
                }

                if (str_starts_with($candidateId, $baseId . '_merge_')) {
                    continue;
                }

                return true;
            }

            return false;
        };

        foreach ($annotationIds as $annId) {
            if (preg_match('/^(.+?)_lines-\d+-\d+$/', $annId, $m) && isset($idLookup[$m[1]])) {
                $suppressed[$m[1]] = true;
            }
        }

        // Suppress enclosing simple-line variants when strictly narrower siblings
        // together cover their full range. E.g. `X_lines-0-4` is redundant once
        // both `X_lines-0-1` and `X_lines-2-4` exist — rendering all three stacks
        // the "big" variant on top of the children, causing visible duplicates.
        $baseToSimpleRanges = [];
        foreach ($annotationIds as $annId) {
            $range = $extractSimpleLineRange($annId);
            if ($range === null) {
                continue;
            }
            $baseToSimpleRanges[$range['base']][] = [
                'id'    => $annId,
                'start' => $range['start'],
                'end'   => $range['end'],
            ];
        }

        foreach ($baseToSimpleRanges as $base => $ranges) {
            if (count($ranges) < 2) {
                continue;
            }

            foreach ($ranges as $outer) {
                $outerStart = $outer['start'];
                $outerEnd   = $outer['end'];
                $outerWidth = $outerEnd - $outerStart;
                if ($outerWidth < 1) {
                    continue;
                }

                // Collect strictly-narrower siblings whose range is fully inside [outerStart, outerEnd].
                $strictChildren = [];
                $hasStrictChild = false;
                foreach ($ranges as $inner) {
                    if ($inner['id'] === $outer['id']) {
                        continue;
                    }
                    if ($inner['start'] < $outerStart || $inner['end'] > $outerEnd) {
                        continue;
                    }
                    $strictChildren[] = $inner;
                    if ($inner['start'] > $outerStart || $inner['end'] < $outerEnd) {
                        $hasStrictChild = true;
                    }
                }

                if (!$hasStrictChild || empty($strictChildren)) {
                    continue;
                }

                // Walk the outer range; every line must be covered by at least one child.
                $covered = true;
                $cursor = $outerStart;
                while ($cursor <= $outerEnd) {
                    $nextEnd = null;
                    foreach ($strictChildren as $child) {
                        if ($child['start'] <= $cursor && $child['end'] >= $cursor) {
                            if ($nextEnd === null || $child['end'] > $nextEnd) {
                                $nextEnd = $child['end'];
                            }
                        }
                    }
                    if ($nextEnd === null) {
                        $covered = false;
                        break;
                    }
                    $cursor = $nextEnd + 1;
                }

                if ($covered) {
                    $suppressed[$outer['id']] = true;
                }
            }
        }

        $baseToSimpleLines = [];
        foreach ($annotationIds as $annId) {
            if (preg_match('/^(.+?)_lines-\d+-\d+$/', $annId, $m)) {
                $baseToSimpleLines[$m[1]][] = $annId;
            }
        }

        foreach ($baseToSimpleLines as $base => $simpleVariants) {
            $prefix = $base . '_';
            $modifierRanges = [];

            foreach ($annotationIds as $annId) {
                if (str_starts_with($annId, $prefix)
                    && str_contains($annId, '-lines-')
                    && !preg_match('/^(.+?)_lines-\d+-\d+$/', $annId)) {
                    $modifierRanges[] = $extractModifierLineRange($annId, $base);
                }
            }

            $modifierRanges = array_values(array_filter($modifierRanges));
            if (empty($modifierRanges)) {
                continue;
            }

            foreach ($simpleVariants as $variantId) {
                $simpleRange = $extractSimpleLineRange($variantId);
                if (!$simpleRange) {
                    $suppressed[$variantId] = true;
                    continue;
                }

                $allCovered = true;
                for ($line = $simpleRange['start']; $line <= $simpleRange['end']; $line++) {
                    $lineCovered = false;
                    foreach ($modifierRanges as $modifierRange) {
                        if ($modifierRange['start'] <= $line && $modifierRange['end'] >= $line) {
                            $lineCovered = true;
                            break;
                        }
                    }

                    if (!$lineCovered) {
                        $allCovered = false;
                        break;
                    }
                }

                if ($allCovered) {
                    $suppressed[$variantId] = true;
                }
            }
        }

        foreach ($annotationIds as $annId) {
            if (isset($suppressed[$annId])) {
                continue;
            }

            $prefix = $annId . '_';
            foreach ($annotationIds as $otherId) {
                if (str_starts_with($otherId, $prefix)
                    && str_contains(substr($otherId, strlen($annId)), '-line')) {
                    $suppressed[$annId] = true;
                    break;
                }
            }
        }

        foreach ($annotationIds as $annId) {
            if (str_contains($annId, 'leader-for-')) {
                $suppressed[$annId] = true;
            }
        }

        foreach ($annotationIds as $annId) {
            if (!str_contains($annId, '_merge_')) {
                continue;
            }

            $parts = explode('_merge_', $annId, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$partA, $partB] = $parts;

            if ($hasDescendantVariant($partA, $annId) || $hasDescendantVariant($partB, $annId)) {
                $suppressed[$annId] = true;
                continue;
            }

            if (isset($idLookup[$partA])) {
                $suppressed[$partA] = true;
            }

            if (isset($idLookup[$partB])) {
                $suppressed[$partB] = true;
            }
        }

        return array_keys($suppressed);
    }
}
