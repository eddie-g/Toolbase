@php
    $badgeConfig = match($status ?? 'error') {
        'ok'        => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-300', 'bg' => 'bg-emerald-50 dark:bg-emerald-900/30',  'label' => 'Connected'],
        'connected' => ['dot' => 'bg-blue-500',    'text' => 'text-blue-700 dark:text-blue-300',       'bg' => 'bg-blue-50 dark:bg-blue-900/30',         'label' => 'Connected'],
        'no_key'    => ['dot' => 'bg-gray-400',    'text' => 'text-gray-600 dark:text-gray-400',       'bg' => 'bg-gray-100 dark:bg-gray-700/50',        'label' => 'No Key'],
        default     => ['dot' => 'bg-red-500',     'text' => 'text-red-700 dark:text-red-300',         'bg' => 'bg-red-50 dark:bg-red-900/30',           'label' => 'Error'],
    };
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ $badgeConfig['bg'] }} {{ $badgeConfig['text'] }}">
    <span class="h-1.5 w-1.5 rounded-full {{ $badgeConfig['dot'] }} {{ $status === 'ok' || $status === 'connected' ? 'animate-pulse' : '' }}"></span>
    {{ $badgeConfig['label'] }}
</span>
