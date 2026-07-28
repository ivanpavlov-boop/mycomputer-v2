<?php

namespace App\Filament\Resources\LeasingApplications\Pages;

use App\Filament\Resources\LeasingApplications\LeasingApplicationResource;
use App\Models\LeasingApplication;
use App\Models\User;
use App\Services\Leasing\LeasingApplicationWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewLeasingApplication extends ViewRecord
{
    protected static string $resource = LeasingApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assign')
                ->label('Назначи отговорник')
                ->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => Gate::allows('assign', $this->record))
                ->schema([
                    Select::make('assigned_to_user_id')
                        ->label('Отговорник')
                        ->options(LeasingApplicationResource::assignableUsers())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, LeasingApplicationWorkflowService $workflow): void {
                    $assignee = User::query()->findOrFail($data['assigned_to_user_id']);
                    $workflow->assign($this->record, $assignee, auth()->user());
                    $this->record->refresh();
                    Notification::make()->title('Отговорникът е назначен')->success()->send();
                }),
            Action::make('changeStatus')
                ->label('Промени статус')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => Gate::allows('changeStatus', $this->record)
                    && app(LeasingApplicationWorkflowService::class)->allowedTransitionOptions($this->record) !== [])
                ->schema([
                    Select::make('status')
                        ->label('Нов статус')
                        ->options(fn (): array => app(LeasingApplicationWorkflowService::class)
                            ->allowedTransitionOptions($this->record))
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data, LeasingApplicationWorkflowService $workflow): void {
                    $workflow->changeStatus($this->record, $data['status'], auth()->user());
                    $this->record->refresh();
                    Notification::make()->title('Статусът е променен')->success()->send();
                }),
            Action::make('addNote')
                ->label('Добави вътрешна бележка')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->visible(fn (): bool => Gate::allows('addNote', $this->record))
                ->modalDescription('Не въвеждайте ЕГН, данни от лична карта, банкови данни или финансови документи.')
                ->schema([
                    Textarea::make('note')
                        ->label('Вътрешна бележка')
                        ->maxLength((int) config('payments.methods.leasing.internal_note_max_length', 2000))
                        ->required(),
                ])
                ->action(function (array $data, LeasingApplicationWorkflowService $workflow): void {
                    $workflow->addNote($this->record, $data['note'], auth()->user());
                    $this->record->refresh();
                    Notification::make()->title('Бележката е добавена')->success()->send();
                }),
        ];
    }

    public function getTitle(): string
    {
        /** @var LeasingApplication $record */
        $record = $this->record;

        return "Лизингова заявка {$record->reference}";
    }
}
