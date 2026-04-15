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
