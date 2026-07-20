<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductWorkflowService;
use App\Support\ProductFormFieldAccess;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

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
            Action::make('viewStorefront')
                ->label('Виж в сайта')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (): ?string => $this->record->storefrontUrl())
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->record->storefrontUrl() !== null),
            Action::make('submitForReview')
                ->label('Изпрати за преглед')
                ->color('warning')
                ->visible(fn (): bool => $this->canRunWorkflowAction(ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW))
                ->requiresConfirmation()
                ->modalHeading('Изпращане за преглед')
                ->modalDescription('Продуктът ще остане скрит, докато не бъде одобрен и публикуван отделно.')
                ->modalSubmitActionLabel('Изпрати за преглед')
                ->action(fn (): null => $this->transitionWorkflow(ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW)),
            Action::make('requestChanges')
                ->label('Върни за корекции')
                ->color('danger')
                ->visible(fn (): bool => $this->canRunWorkflowAction(ProductWorkflowService::ACTION_REQUEST_CHANGES))
                ->requiresConfirmation()
                ->modalHeading('Връщане за корекции')
                ->modalDescription('Продуктът ще бъде скрит. Опишете ясно необходимите корекции.')
                ->modalSubmitActionLabel('Върни за корекции')
                ->form([
                    Textarea::make('review_notes')
                        ->label('Бележка за корекция')
                        ->rows(3)
                        ->required(),
                ])
                ->action(fn (array $data): null => $this->transitionWorkflow(ProductWorkflowService::ACTION_REQUEST_CHANGES, $data['review_notes'] ?? null)),
            Action::make('approve')
                ->label('Одобри')
                ->color('info')
                ->visible(fn (): bool => $this->canRunWorkflowAction(ProductWorkflowService::ACTION_APPROVE))
                ->requiresConfirmation()
                ->modalHeading('Одобряване на продукт')
                ->modalDescription('Одобрението не публикува продукта. Той ще остане скрит до отделно публикуване.')
                ->modalSubmitActionLabel('Одобри')
                ->action(fn (): null => $this->transitionWorkflow(ProductWorkflowService::ACTION_APPROVE)),
            Action::make('publish')
                ->label('Публикувай')
                ->color('success')
                ->visible(fn (): bool => $this->canRunWorkflowAction(ProductWorkflowService::ACTION_PUBLISH))
                ->requiresConfirmation()
                ->modalHeading('Публикуване на продукт')
                ->modalDescription('След потвърждение продуктът ще стане публично видим, ако покрива техническите изисквания.')
                ->modalSubmitActionLabel('Публикувай')
                ->action(fn (): null => $this->publishProduct()),
            Action::make('hide')
                ->label('Скрий')
                ->color('danger')
                ->visible(fn (): bool => $this->canRunWorkflowAction(ProductWorkflowService::ACTION_HIDE))
                ->requiresConfirmation()
                ->modalHeading('Скриване на продукт')
                ->modalDescription('Продуктът ще изчезне от публичния каталог и търсенето. Историята на публикуването ще се запази.')
                ->modalSubmitActionLabel('Скрий')
                ->action(fn (): null => $this->transitionWorkflow(ProductWorkflowService::ACTION_HIDE)),
            DeleteAction::make()->label('Изтриване'),
            RestoreAction::make()->label('Възстановяване'),
            ForceDeleteAction::make()->label('Изтрий завинаги'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->isActiveAdminAccount()) {
            throw new AuthorizationException('Необходим е активен администратор.');
        }

        return ProductFormFieldAccess::sanitize($data, $actor);
    }

    protected function canRunWorkflowAction(string $action): bool
    {
        return app(ProductWorkflowService::class)->can($this->record, $action, auth()->user());
    }

    protected function publishProduct(): null
    {
        $this->record = app(ProductWorkflowService::class)->publish(
            $this->record,
            $this->workflowActor(),
        );

        $this->fillForm();

        $storefrontUrl = $this->record->storefrontUrl();
        $notification = Notification::make()
            ->title('Продуктът е публикуван успешно')
            ->body(Product::workflowStatusLabel($this->record->workflow_status))
            ->success();

        if ($storefrontUrl !== null) {
            $notification->actions([
                Action::make('viewStorefront')
                    ->label('Виж в сайта')
                    ->url($storefrontUrl)
                    ->openUrlInNewTab(),
            ]);
        }

        $notification->send();

        $this->redirect(ProductResource::getUrl('index'));

        return null;
    }

    protected function transitionWorkflow(string $action, ?string $notes = null): null
    {
        $this->record = app(ProductWorkflowService::class)->transition(
            $this->record,
            $action,
            $this->workflowActor(),
            $notes,
        );

        $this->fillForm();

        Notification::make()
            ->title(match ($action) {
                ProductWorkflowService::ACTION_SUBMIT_FOR_REVIEW => 'Продуктът е изпратен за преглед',
                ProductWorkflowService::ACTION_REQUEST_CHANGES => 'Продуктът е върнат за корекции',
                ProductWorkflowService::ACTION_APPROVE => 'Продуктът е одобрен, но остава скрит',
                ProductWorkflowService::ACTION_HIDE => 'Продуктът е скрит',
                default => 'Работният статус е обновен',
            })
            ->body(Product::workflowStatusLabel($this->record->workflow_status))
            ->success()
            ->send();

        return null;
    }

    /** @throws AuthorizationException */
    protected function workflowActor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Необходим е активен администратор.');
        }

        return $user;
    }
}
