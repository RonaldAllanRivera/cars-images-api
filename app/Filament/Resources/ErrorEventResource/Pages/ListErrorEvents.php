<?php

namespace App\Filament\Resources\ErrorEventResource\Pages;

use App\Console\Commands\PruneErrorEvents;
use App\Filament\Resources\ErrorEventResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListErrorEvents extends ListRecords
{
    protected static string $resource = ErrorEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Pruning is a button because there is no cron on the production
            // host: a scheduled prune would never fire, so clearing the log has
            // to be something the operator can do from here. The retention rule
            // itself lives in the command, called below, rather than being
            // reimplemented on the page.
            Actions\Action::make('prune')
                ->label('Prune old entries')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => sprintf(
                    'This deletes %s entr%s older than %d days. Recent entries are kept.',
                    number_format(PruneErrorEvents::prunableCount()),
                    PruneErrorEvents::prunableCount() === 1 ? 'y' : 'ies',
                    PruneErrorEvents::retentionDays(),
                ))
                ->action(function () {
                    $deleted = PruneErrorEvents::prune();

                    Notification::make()
                        ->title("Pruned {$deleted} error event(s)")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return sprintf(
            'Failures recorded across CSV uploads, search runs and image downloads. Entries are kept for %d days and are removed only by pruning.',
            PruneErrorEvents::retentionDays(),
        );
    }
}
