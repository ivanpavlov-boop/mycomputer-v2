<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return 'Редакция на продукт';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitForReview')
                ->label('Изпрати за преглед')
                ->color('warning')
                ->visible(fn (): bool => $this->record->canTransitionWorkflowTo(Product::WORKFLOW_PENDING_REVIEW))
                ->action(fn (): null => $this->transitionWorkflow(Product::WORKFLOW_PENDING_REVIEW)),
            Action::make('requestChanges')
                ->label('Върни за корекции')
                ->color('gray')
                ->visible(fn (): bool => $this->record->canTransitionWorkflowTo(Product::WORKFLOW_CHANGES_REQUESTED))
                ->form([
                    Textarea::make('review_notes')
                        ->label('Бележка за корекция')
                        ->rows(3)
                        ->required(),
                ])
                ->action(fn (array $data): null => $this->transitionWorkflow(Product::WORKFLOW_CHANGES_REQUESTED, $data['review_notes'] ?? null)),
            Action::make('approve')
                ->label('Одобри')
                ->color('success')
                ->visible(fn (): bool => $this->record->canTransitionWorkflowTo(Product::WORKFLOW_APPROVED))
                ->action(fn (): null => $this->transitionWorkflow(Product::WORKFLOW_APPROVED)),
            Action::make('publish')
                ->label('Публикувай')
                ->color('success')
                ->visible(fn (): bool => $this->record->canTransitionWorkflowTo(Product::WORKFLOW_PUBLISHED))
                ->requiresConfirmation()
                ->action(fn (): null => $this->transitionWorkflow(Product::WORKFLOW_PUBLISHED)),
            Action::make('unpublish')
                ->label('Скрий')
                ->color('danger')
                ->visible(fn (): bool => $this->record->workflow_status === Product::WORKFLOW_PUBLISHED && auth()->user()?->canPublishProducts())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->forceFill([
                        'workflow_status' => Product::WORKFLOW_APPROVED,
                        'active' => false,
                        'product_status' => 'hidden',
                    ])->save();

                    $this->refreshFormData(['workflow_status', 'active', 'product_status']);

                    Notification::make()
                        ->title('Продуктът е скрит')
                        ->success()
                        ->send();
                }),
            DeleteAction::make()->label('Изтриване'),
            RestoreAction::make()->label('Възстановяване'),
            ForceDeleteAction::make()->label('Изтрий завинаги'),
        ];
    }

    protected function transitionWorkflow(string $status, ?string $notes = null): null
    {
        $this->record->transitionWorkflowTo($status, auth()->user(), $notes);
        $this->record->refresh();
        $this->refreshFormData([
            'workflow_status',
            'active',
            'product_status',
            'published_at',
            'review_notes',
        ]);

        Notification::make()
            ->title('Работният статус е обновен')
            ->body(self::workflowStatusLabel($this->record->workflow_status))
            ->success()
            ->send();

        return null;
    }

    protected static function workflowStatusLabel(?string $status): string
    {
        return [
            Product::WORKFLOW_DRAFT => 'Чернова',
            Product::WORKFLOW_PENDING_REVIEW => 'За преглед',
            Product::WORKFLOW_CHANGES_REQUESTED => 'Върнат за корекции',
            Product::WORKFLOW_APPROVED => 'Одобрен',
            Product::WORKFLOW_PUBLISHED => 'Публикуван',
        ][$status] ?? 'Неизвестен';
    }
}
