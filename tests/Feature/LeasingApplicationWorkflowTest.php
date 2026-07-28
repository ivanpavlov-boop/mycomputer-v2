<?php

namespace Tests\Feature;

use App\Models\LeasingApplication;
use App\Models\LeasingApplicationActivity;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Leasing\LeasingApplicationWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class LeasingApplicationWorkflowTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_manage_orders_staff_can_assign_note_and_follow_allowed_transitions(): void
    {
        $application = $this->application('leasing-workflow');
        $manager = $this->admin(User::ROLE_ORDER_MANAGER);
        $workflow = app(LeasingApplicationWorkflowService::class);

        $workflow->assign($application, $manager, $manager);
        $workflow->addNote($application, '<b>Обадете се утре.</b>', $manager);
        $workflow->changeStatus($application, LeasingApplication::STATUS_CONTACT_PENDING, $manager);
        $workflow->changeStatus($application->fresh(), LeasingApplication::STATUS_CONTACTED, $manager);
        $workflow->changeStatus($application->fresh(), LeasingApplication::STATUS_SENT_TO_PARTNER, $manager);
        $workflow->changeStatus($application->fresh(), LeasingApplication::STATUS_APPROVED, $manager);

        $this->assertSame($manager->getKey(), $application->fresh()->assigned_to_user_id);
        $this->assertSame(LeasingApplication::STATUS_APPROVED, $application->fresh()->status);
        $this->assertDatabaseHas('leasing_application_activities', [
            'event_type' => LeasingApplicationActivity::EVENT_NOTE_ADDED,
            'note' => 'Обадете се утре.',
            'actor_user_id' => $manager->getKey(),
        ]);
        $this->assertSame([], $workflow->allowedTransitionOptions($application->fresh()));
    }

    public function test_viewer_auditor_is_read_only_and_invalid_transition_is_rejected(): void
    {
        $application = $this->application('leasing-workflow-viewer');
        $viewer = $this->admin(User::ROLE_VIEWER_AUDITOR);
        $manager = $this->admin(User::ROLE_ORDER_MANAGER);
        $workflow = app(LeasingApplicationWorkflowService::class);

        $this->assertTrue($viewer->can('view', $application));
        $this->assertFalse($viewer->can('update', $application));

        try {
            $workflow->addNote($application, 'Не трябва да се запише.', $viewer);
            $this->fail('Viewer/Auditor must not add notes.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('leasing_application_activities', [
                'note' => 'Не трябва да се запише.',
            ]);
        }

        $this->expectException(ValidationException::class);
        $workflow->changeStatus($application, LeasingApplication::STATUS_APPROVED, $manager);
    }

    public function test_activity_records_cannot_be_edited_or_deleted(): void
    {
        $activity = $this->application('leasing-immutable-activity')->activities()->firstOrFail();

        try {
            $activity->update(['note' => 'Changed']);
            $this->fail('Activity update must fail.');
        } catch (LogicException) {
            $this->assertNull($activity->fresh()->note);
        }

        $this->expectException(LogicException::class);
        $activity->delete();
    }

    private function application(string $name): LeasingApplication
    {
        config()->set('payments.methods.leasing.enabled', true);
        $cart = $this->prepareCheckoutCart($name);
        PaymentMethod::query()->where('code', 'leasing')->update(['status' => 'active']);
        $this->submitCheckout($cart, $name, [
            'payment_method' => 'leasing',
            'leasing_application' => [
                'term_months' => 12,
                'down_payment' => '0.00',
                'contact_method' => 'phone',
                'contact_time' => null,
                'note' => null,
                'consent' => true,
            ],
        ])->assertCreated();

        return LeasingApplication::query()->sole();
    }

    private function admin(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
