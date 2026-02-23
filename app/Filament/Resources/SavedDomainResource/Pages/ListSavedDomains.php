<?php

namespace App\Filament\Resources\SavedDomainResource\Pages;

use App\Filament\Resources\SavedDomainResource;
use App\Jobs\RefreshSavedDomainAvailabilityJob;
use App\Models\SavedDomain;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSavedDomains extends ListRecords
{
    protected static string $resource = SavedDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refreshAvailability')
                ->label('Refresh Availability')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Refresh Domain Availability')
                ->modalDescription(
                    'This queues a background job to re-check availability for all ' .
                    SavedDomain::count() . ' saved domain(s). Results update once jobs complete.'
                )
                ->modalSubmitActionLabel('Refresh All')
                ->action(function () {
                    $userIds = SavedDomain::distinct()->pluck('user_id');

                    foreach ($userIds as $userId) {
                        RefreshSavedDomainAvailabilityJob::dispatch($userId);
                    }

                    Notification::make()
                        ->title('Refresh queued')
                        ->body('Queued availability checks for ' . $userIds->count() . ' user(s).')
                        ->success()
                        ->send();
                }),
        ];
    }
}
