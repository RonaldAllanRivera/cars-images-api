<?php

namespace App\Filament\Pages;

use App\Models\CarImage;
use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class Results extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Results';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.results';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CarImage::query()
                    ->whereHas('search', fn (Builder $q) => $q->whereNotNull('csv_import_id'))
                    ->with('search.csvImport')
            )
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('Thumbnail')
                    ->imageSize(120),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Name')
                    ->state(fn (CarImage $record) => "{$record->year} {$record->make} {$record->model}")
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function ($q) use ($search) {
                            $q->where('make', 'like', "%{$search}%")
                              ->orWhere('model', 'like', "%{$search}%")
                              ->orWhere('year', 'like', "%{$search}%");
                        });
                    }),
                Tables\Columns\TextColumn::make('search.csvImport.original_filename')
                    ->label('Source CSV')
                    ->limit(30),
                Tables\Columns\TextColumn::make('year')->sortable(),
                Tables\Columns\TextColumn::make('make')->sortable(),
                Tables\Columns\TextColumn::make('model'),
            ])
            ->filters([
                SelectFilter::make('csv_import_id')
                    ->label('CSV Import')
                    ->relationship('search.csvImport', 'original_filename'),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn (CarImage $record) => $record->source_url, true),
                Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (CarImage $record) {
                        return redirect()->route('car-images.download', ['carImage' => $record->id]);
                    }),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkAction::make('downloadZip')
                    ->label('Download Selected as ZIP')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->action(function ($records) {
                        $ids = $records->pluck('id')->all();
                        $url = route('batch-downloads.zip');

                        $this->dispatch('post-download', url: $url, ids: $ids);

                        Notification::make()
                            ->title('Preparing ZIP…')
                            ->success()
                            ->send();
                    }),
                Actions\BulkAction::make('exportCsv')
                    ->label('Export Selected as CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function ($records) {
                        $ids = $records->pluck('id')->all();
                        $url = route('batch-downloads.csv');

                        $this->dispatch('post-download', url: $url, ids: $ids);

                        Notification::make()
                            ->title('Preparing CSV…')
                            ->success()
                            ->send();
                    }),
                Actions\DeleteBulkAction::make(),
            ])
            ->paginated([24, 48, 96]);
    }

    public static function getRouteName(?Panel $panel = null): string
    {
        return 'filament.admin.pages.results';
    }
}
