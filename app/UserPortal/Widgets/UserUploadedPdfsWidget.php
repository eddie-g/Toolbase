<?php

namespace App\UserPortal\Widgets;

use App\Models\Document;
use App\Models\UserActivity;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UserUploadedPdfsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Uploaded PDFs';

    protected static string $view = 'user-portal.widgets.user-uploaded-pdfs-widget';

    public string $viewMode = 'cards';

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['table', 'cards'], true) ? $mode : 'table';
    }

    public function openUrl(Document $document): string
    {
        return $document->mode === 'guided'
            ? route('documents.guided', $document)
            : route('documents.editNew', [
                'document' => $document,
                'pdfjs' => 1,
                'from' => 'admin',
                'return_to' => route('filament.user.pages.pdf-generator'),
            ]);
    }

    public function deleteDocument(int $documentId): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $record = Document::query()->find($documentId);
        if (!$record) {
            return;
        }

        $canDelete = ((int) ($record->user_id ?? 0) === (int) $user->id)
            || UserActivity::query()
                ->where('user_id', $user->id)
                ->where('document_id', $record->id)
                ->exists();

        if (!$canDelete) {
            Notification::make()
                ->title('You are not allowed to delete this PDF')
                ->danger()
                ->send();
            return;
        }

        DB::table('pdf_extractions_fitz')
            ->where('document_id', $record->id)
            ->delete();

        if ($record->path) {
            Storage::delete($record->path);
        }

        if ($record->original_backup_path) {
            Storage::delete($record->original_backup_path);
        }

        $record->delete();
        $this->resetTable();

        Notification::make()
            ->title('PDF deleted')
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        $userDocumentIds = UserActivity::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereRaw('1 = 0'))
            ->whereNotNull('document_id')
            ->pluck('document_id');

        return $table
            ->heading(null)
            ->query(
                Document::query()
                    ->when(
                        $user,
                        fn ($q) => $q->where('user_id', $user->id)->orWhereIn('id', $userDocumentIds),
                        fn ($q) => $q->whereRaw('1 = 0')
                    )
                    ->latest()
                    ->limit(250)
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('original_name')
                    ->label('File Name')
                    ->searchable()
                    ->wrap()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('mode')
                    ->label('Mode')
                    ->placeholder('standard')
                    ->badge(),

                Tables\Columns\TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn ($state) => $state ? number_format(((int) $state) / 1024, 1) . ' KB' : '—')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Document $record) => $this->openUrl($record))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('delete')
                    ->label('DELETE')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete PDF')
                    ->modalDescription('This will permanently delete the uploaded PDF.')
                    ->action(fn (Document $record) => $this->deleteDocument((int) $record->id)),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
