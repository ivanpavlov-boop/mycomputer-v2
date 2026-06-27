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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitForReview')
                ->label('Submit for review')
                ->color('warning')
                ->visible(fn (): bool => $this->record->canTransitionWorkflowTo(Product::WORKFLOW_PENDING_REVIEW))
                ->action(fn (): null => $this->transitionWorkflow(Product::WORKFLOW_PENDING_REVIEW)),
            Action::make('requestChanges')
                ->label('Request changes')
                ->color('gray')
                ->visible(fn (): bool => $this->record->canTransitionWorkflowTo(Product::WORKFLOW_CHANGES_REQUESTED))
                ->form([
                    Textarea::make('review_notes')
                        ->label('Correction note')
                        ->rows(3)
                        ->required(),
                ])
                ->action(fn (array $data): null => $this->transitionWorkflow(Product::WORKFLOW_CHANGES_REQUESTED, $data['review_notes'] ?? null)),
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->visible(fn (): bool => $this->record->canTransitionWorkflowTo(Product::WORKFLOW_APPROVED))
                ->action(fn (): null => $this->transitionWorkflow(Product::WORKFLOW_APPROVED)),
            Action::make('publish')
                ->label('Publish')
                ->color('success')
                ->visible(fn (): bool => $this->record->canTransitionWorkflowTo(Product::WORKFLOW_PUBLISHED))
                ->requiresConfirmation()
                ->action(fn (): null => $this->transitionWorkflow(Product::WORKFLOW_PUBLISHED)),
            Action::make('unpublish')
                ->label('Unpublish')
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
                        ->title('Product unpublished')
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
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
            ->title('Product workflow updated')
            ->body(Product::workflowStatusLabel($this->record->workflow_status))
            ->success()
            ->send();

        return null;
    }
}
