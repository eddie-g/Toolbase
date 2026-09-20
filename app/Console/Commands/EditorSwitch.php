<?php

namespace App\Console\Commands;

use App\Support\EditorSwitches;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EditorSwitch extends Command
{
    protected $signature = 'editor:switch
        {switch? : editor or export}
        {state? : on, off, or reset (back to what the configuration says)}';

    protected $description = 'Turn the PDF editor or the exports off and on at once, without a deploy; with no arguments, show both';

    public function handle(EditorSwitches $switches): int
    {
        $switch = $this->argument('switch');
        $state = $this->argument('state');

        if ($switch !== null) {
            if (! in_array($switch, EditorSwitches::NAMES, true) || ! in_array($state, ['on', 'off', 'reset'], true)) {
                $this->error('Usage: editor:switch editor|export on|off|reset');

                return self::INVALID;
            }
            $switches->set($switch, match ($state) {
                'on' => true,
                'off' => false,
                'reset' => null,
            });
            // Who turned the editor off, and when, belongs in the log.
            Log::warning('Editor switch changed', ['switch' => $switch, 'state' => $state]);
        }

        $this->table(['switch', 'now', 'set by', 'configured'], array_map(fn (string $name) => [
            $name,
            $switches->enabled($name) ? 'on' : 'OFF',
            $switches->override($name) === null ? 'configuration' : 'editor:switch',
            config("pdf_editor.switches.{$name}.enabled") ? 'on' : 'off',
        ], EditorSwitches::NAMES));

        return self::SUCCESS;
    }
}
