<?php

namespace App\FilamentPages;

use App\Models\Admin;
use App\Models\AuthEvent;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Sign-ins, failures, lockouts and password resets on both guards
 * (auth_events, written by App\Listeners\RecordAuthEvent). For operators:
 * what incident response looks at first.
 */
class AuthEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Sign-in activity';

    protected static ?string $slug = 'sign-in-activity';

    protected static string $view = 'filament.pages.auth-events';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && $admin->isOperator();
    }

    public function getTitle(): string
    {
        return 'Sign-in activity';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(AuthEvent::query())
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->poll('30s')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M j, H:i:s')->description(fn (AuthEvent $record) => $record->created_at?->diffForHumans())->sortable(),
                TextColumn::make('event')->badge()->color(fn (string $state) => match ($state) {
                    'login' => 'success',
                    'failed', 'lockout' => 'danger',
                    'password_reset', 'other_device_logout' => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('guard')->badge()->color(fn (string $state) => $state === 'admin' ? 'info' : 'gray'),
                TextColumn::make('email')->searchable()->placeholder('unknown'),
                TextColumn::make('ip')->label('Address')->searchable()->copyable(),
                TextColumn::make('user_agent')->label('Browser')->limit(48)->tooltip(fn (AuthEvent $record) => $record->user_agent)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('event')->options(fn () => AuthEvent::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                SelectFilter::make('guard')->options(['web' => 'Users', 'admin' => 'Admins']),
            ]);
    }
}
