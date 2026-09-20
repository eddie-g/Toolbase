<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The editor's kill switches (config pdf_editor.switches). The configured
 * value is the default; an override in the cache wins, so one command turns
 * a switch for every container at once, without a deploy.
 */
class EditorSwitches
{
    public const NAMES = ['editor', 'export'];

    public function enabled(string $switch): bool
    {
        $override = $this->override($switch);

        return $override ?? (bool) config("pdf_editor.switches.{$switch}.enabled", true);
    }

    /** true / false when someone has set it by hand, null when the configuration decides. */
    public function override(string $switch): ?bool
    {
        try {
            $value = Cache::get($this->key($switch));
        } catch (\Throwable) {
            // The cache being down must not take the editor down with it.
            return null;
        }

        return match ($value) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    public function set(string $switch, ?bool $enabled): void
    {
        $enabled === null
            ? Cache::forget($this->key($switch))
            : Cache::forever($this->key($switch), $enabled ? 'on' : 'off');
    }

    /** The switch that is off for this route, or null when the request may go ahead. */
    public function blocking(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }
        $class = config('editor_limits.routes')[$routeName] ?? null;

        foreach (self::NAMES as $switch) {
            $covers = in_array($routeName, (array) config("pdf_editor.switches.{$switch}.routes"), true)
                || ($class !== null && in_array($class, (array) config("pdf_editor.switches.{$switch}.classes"), true));
            if ($covers && ! $this->enabled($switch)) {
                return $switch;
            }
        }

        return null;
    }

    public function message(string $switch): string
    {
        return (string) config("pdf_editor.switches.{$switch}.message");
    }

    private function key(string $switch): string
    {
        return 'editor-switch:'.$switch;
    }
}
