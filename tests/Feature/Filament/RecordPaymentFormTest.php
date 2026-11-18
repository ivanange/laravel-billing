<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Filament;

use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\PanelRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ivanange\Billing\Events\PaymentRecorded;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Filament\BillingPlugin;
use Ivanange\Billing\Filament\Forms\PayerFields;
use Ivanange\Billing\Filament\Forms\RecordPaymentForm;
use Ivanange\Billing\Filament\RelationManagers\CoveragePeriodsRelationManager;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\PlanPrice;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Tests\FilamentTestCase;
use Ivanange\Billing\Tests\Fixtures\Company;
use Ivanange\Billing\Tests\Fixtures\Filament\Pages\EditMember;
use Ivanange\Billing\Tests\Fixtures\Filament\Pages\ListMembers;
use Ivanange\Billing\Tests\Fixtures\Guardian;
use Ivanange\Billing\Tests\Fixtures\Member;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Livewire\Livewire;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class RecordPaymentFormTest extends FilamentTestCase
{
    use OnlineHelpers;

    private const PROOFS = 'billing/proofs';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_proof_uploaded_through_the_action_is_stored_and_downloads(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway(['settings' => ['require_proof' => true]]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($member), $this->form($price, $gateway, [
                'proof' => UploadedFile::fake()->image('receipt.jpg'),
            ]))
            ->assertHasNoFormErrors();

        $payment = Payment::query()->sole();
        $this->assertMatchesRegularExpression('#^billing/proofs/[0-9A-HJKMNP-TV-Z]{26}\.jpg$#', (string) $payment->proof_path);
        $this->assertSame([$payment->proof_path], Storage::disk('local')->files(self::PROOFS));
        $this->assertTrue($member->isCovered());

        $this->get(route('billing.payments.proof', $payment))->assertOk()->assertDownload(basename($payment->proof_path));
    }

    public function test_a_failed_attempt_leaves_no_file_behind_and_the_retry_reuses_the_upload(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway(['settings' => ['require_proof' => true]]);
        $this->record(['billables' => [$member], 'plan' => $price->plan, 'gateway' => $gateway, 'proofPath' => null, 'method' => 'complimentary', 'amountPerBillable' => 0]);

        $page = Livewire::test(ListMembers::class)
            ->mountAction(TestAction::make('recordPayment')->table($member))
            ->fillForm($this->form($price, $gateway, ['starts_on' => '2026-03-20', 'proof' => UploadedFile::fake()->image('receipt.jpg')]))
            ->callMountedAction()
            ->assertNotified(__('billing::billing.ui.record.failed'));

        $this->assertSame(1, Payment::query()->count(), 'the overlapping payment was refused');
        $this->assertSame([], Storage::disk('local')->files(self::PROOFS));

        $page->fillForm(['starts_on' => '2026-04-10'])->callMountedAction()->assertHasNoFormErrors();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertNotNull($payment->proof_path);
        $this->assertSame([$payment->proof_path], Storage::disk('local')->files(self::PROOFS));
    }

    public function test_a_submitted_path_is_never_accepted_as_proof(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway(['settings' => ['require_proof' => true]]);
        $someoneElses = self::PROOFS.'/'.Str::ulid().'.jpg';
        Storage::disk('local')->put($someoneElses, 'their receipt');

        foreach ([$someoneElses, self::PROOFS.'/../../.env', 'elsewhere/receipt.jpg'] as $path) {
            // The shape a browser sends for a file upload field's state.
            Livewire::test(ListMembers::class)
                ->callAction(TestAction::make('recordPayment')->table($member), $this->form($price, $gateway, ['proof' => [(string) Str::uuid() => $path]]))
                ->assertNotified(__('billing::billing.ui.record.failed'));
        }

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame([$someoneElses], Storage::disk('local')->files(self::PROOFS));
    }

    /**
     * @return iterable<string, array{0: callable(): string}>
     */
    public static function tamperedPaths(): iterable
    {
        yield 'traversal' => [fn (): string => self::PROOFS.'/../'.Str::ulid().'.jpg'];
        yield 'other directory' => [fn (): string => 'invoices/'.Str::ulid().'.jpg'];
        yield 'nested directory' => [fn (): string => self::PROOFS.'/x/'.Str::ulid().'.jpg'];
        yield 'not our name' => [fn (): string => self::PROOFS.'/'.Str::random(40).'.jpg'];
        yield 'other extension' => [fn (): string => self::PROOFS.'/'.Str::ulid().'.php'];
        yield 'missing file' => [fn (): string => self::PROOFS.'/'.Str::ulid().'.pdf'];
    }

    #[DataProvider('tamperedPaths')]
    public function test_a_draft_refuses_a_proof_path_it_did_not_store(callable $path): void
    {
        $path = $path();
        if (! str_ends_with($path, '.pdf')) {
            Storage::disk('local')->put($path, 'x');
        }

        $this->expectException(InvalidArgumentException::class);
        RecordPaymentForm::draft($this->form($this->monthlyPrice(), $this->gateway(), ['proof' => $path]), [$this->member()]);
    }

    public function test_a_draft_refuses_a_proof_another_payment_uses(): void
    {
        $path = self::PROOFS.'/'.Str::ulid().'.pdf';
        Storage::disk('local')->put($path, 'x');
        $this->record(['proofPath' => $path]);

        $this->expectException(InvalidArgumentException::class);
        RecordPaymentForm::draft($this->form($this->monthlyPrice(), $this->gateway(), ['proof' => $path]), [$this->member('Other')]);
    }

    public function test_the_relation_manager_records_for_its_owner_and_links_to_the_payment(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();

        $manager = Livewire::test(CoveragePeriodsRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditMember::class])
            ->callAction(TestAction::make('recordPayment')->table(), $this->form($price, $gateway))
            ->assertHasNoFormErrors();

        $period = CoveragePeriod::query()->sole();
        $this->assertTrue($period->billable->is($member));
        $manager->assertActionVisible(TestAction::make('payment')->table($period));
    }

    #[DefineEnvironment('withoutThePaymentsResource')]
    public function test_the_relation_manager_hides_the_payment_link_when_the_payments_resource_is_off(): void
    {
        $member = $this->member();
        $this->record(['billables' => [$member]]);

        Livewire::test(CoveragePeriodsRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditMember::class])
            ->assertActionHidden(TestAction::make('payment')->table(CoveragePeriod::query()->sole()));
    }

    protected function withoutThePaymentsResource(Application $app): void
    {
        $app['config']->set('billing-tests.payments_resource', false);
    }

    public function test_only_billing_managers_can_override_an_overlap(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();
        $this->record(['billables' => [$member], 'plan' => $price->plan, 'gateway' => $gateway]);
        $overlapping = $this->form($price, $gateway, ['starts_on' => '2026-03-20', 'allow_overlap' => true]);

        $recorder = $this->user('Recorder');
        Gate::define('billing.manage', fn ($user): bool => $user->isNot($recorder));

        $this->actingAs($recorder);
        Livewire::test(ListMembers::class)
            ->mountAction(TestAction::make('recordPayment')->table($member))
            ->assertFormFieldHidden('allow_overlap')
            ->fillForm($overlapping)
            ->callMountedAction()
            ->assertNotified(__('billing::billing.ui.record.failed'));
        $this->assertSame(1, Payment::query()->count(), 'a recorder cannot override the overlap');

        $this->actingAs($this->user('Manager'));
        Livewire::test(ListMembers::class)
            ->mountAction(TestAction::make('recordPayment')->table($member))
            ->assertFormFieldVisible('allow_overlap')
            ->fillForm($overlapping)
            ->callMountedAction()
            ->assertHasNoFormErrors();
        $this->assertSame(2, Payment::query()->count(), 'a manager can');
        $this->assertSame(2, $member->coveragePeriods()->count());
    }

    public function test_the_overlap_is_rejected_by_the_form_without_the_override(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();
        $this->record(['billables' => [$member], 'plan' => $price->plan, 'gateway' => $gateway]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($member), $this->form($price, $gateway, ['starts_on' => '2026-03-20']))
            ->assertNotified(__('billing::billing.ui.record.failed'));

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_the_admin_can_name_a_payer(): void
    {
        $child = $this->member('Kwame');
        $parent = $this->member('Abena');
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();

        Livewire::test(ListMembers::class)
            ->mountAction(TestAction::make('recordPayment')->table($child))
            ->assertFormFieldVisible('payer_type')
            ->assertFormFieldHidden('payer_id')
            ->fillForm($this->form($price, $gateway, ['payer_type' => $parent->getMorphClass(), 'payer_id' => $parent->id]))
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $payment = Payment::query()->sole();
        $this->assertTrue($payment->payer->is($parent));
        $this->assertTrue($child->subscriptions()->sole()->payer->is($parent));
    }

    public function test_without_a_payer_the_billable_pays_and_an_unregistered_payer_type_is_refused(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($member), $this->form($price, $gateway, ['payer_type' => 'users', 'payer_id' => $this->user()->id]))
            ->assertHasFormErrors(['payer_type']);
        $this->assertSame(0, Payment::query()->count());

        try {
            RecordPaymentForm::draft($this->form($price, $gateway, ['payer_type' => 'users', 'payer_id' => 1]), [$member]);
            $this->fail('An unregistered payer type must be refused');
        } catch (InvalidArgumentException) {
        }

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($member), $this->form($price, $gateway))
            ->assertHasNoFormErrors();

        $payment = Payment::query()->sole();
        $this->assertTrue($payment->payer->is($member), 'the billable paid for itself');
        $this->assertTrue($member->subscriptions()->sole()->payer->is($member));
    }

    public function test_the_payer_search_finds_registered_billables_by_name_or_key(): void
    {
        $abena = $this->member('Abena');
        $this->member('Kofi');
        $type = $abena->getMorphClass();

        $this->assertSame([$abena->id => 'Abena'], PayerFields::search($type, 'ben'));
        $this->assertSame([$abena->id => 'Abena'], PayerFields::search($type, (string) $abena->id));
        $this->assertSame([], PayerFields::search('users', 'Admin'));
        $this->assertSame([], PayerFields::search($type, '  '));
    }

    public function test_the_group_action_records_one_payment_for_the_selected_records(): void
    {
        $members = collect(['A', 'B', 'C'])->map(fn (string $name): Member => $this->member($name));
        $this->member('Not selected');
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();

        Livewire::test(ListMembers::class)
            ->selectTableRecords($members->pluck('id')->all())
            ->callAction(TestAction::make('recordGroupPayment')->table()->bulk(), $this->form($price, $gateway))
            ->assertHasNoFormErrors()
            ->assertNotified(__('billing::billing.ui.record.done'));

        $payment = Payment::query()->sole();
        $this->assertSame(4500, $payment->amount);
        $this->assertNull($payment->payer_id, 'a group payment without a payer names nobody');
        $this->assertSame(3, $payment->allocations()->count());
        $this->assertSame(3, Member::query()->covered()->count());
        foreach ($members as $member) {
            $this->assertTrue($member->subscriptions()->sole()->payer->is($member));
        }
    }

    public function test_the_group_action_can_name_one_payer_for_everyone(): void
    {
        $members = collect(['A', 'B'])->map(fn (string $name): Member => $this->member($name));
        $treasurer = $this->member('Treasurer');
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();

        Livewire::test(ListMembers::class)
            ->selectTableRecords($members->pluck('id')->all())
            ->callAction(TestAction::make('recordGroupPayment')->table()->bulk(), $this->form($price, $gateway, [
                'payer_type' => $treasurer->getMorphClass(),
                'payer_id' => $treasurer->id,
            ]))
            ->assertHasNoFormErrors();

        $this->assertTrue(Payment::query()->sole()->payer->is($treasurer));
        $this->assertSame([$treasurer->id, $treasurer->id], Subscription::query()->orderBy('id')->pluck('payer_id')->all());
    }

    public function test_the_payer_search_ignores_case(): void
    {
        $abena = $this->member('Abena');
        $type = $abena->getMorphClass();

        $this->assertSame([$abena->id => 'Abena'], PayerFields::search($type, 'abena'));
        $this->assertSame([$abena->id => 'Abena'], PayerFields::search($type, 'ABENA'));
    }

    public function test_the_payer_search_by_key_never_breaks_the_query(): void
    {
        $this->registerGuardians();
        $abena = $this->member('Abena');
        $guardian = Guardian::query()->create(['name' => 'Yaw']);
        $members = $abena->getMorphClass();
        $guardians = $guardian->getMorphClass();

        $this->assertSame([], PayerFields::search($members, '99999999999999999999'), 'beyond bigint');
        $this->assertSame([], PayerFields::search($members, '2147483648'));
        $this->assertSame([$abena->id => 'Abena'], PayerFields::search($members, (string) $abena->id));

        $this->assertSame([], PayerFields::search($guardians, '123'), 'digits are no uuid');
        $this->assertSame([$guardian->id => 'Yaw'], PayerFields::search($guardians, $guardian->id));
        $this->assertSame([$guardian->id => 'Yaw'], PayerFields::search($guardians, strtoupper($guardian->id)));
    }

    public function test_a_tampered_payer_id_is_refused_as_a_form_error(): void
    {
        $this->registerGuardians();
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();
        $guardian = Guardian::query()->create(['name' => 'Yaw']);

        foreach ([[$member->getMorphClass(), 'abc'], [$member->getMorphClass(), '99999999999999999999'], [$guardian->getMorphClass(), '123'], [$guardian->getMorphClass(), 'not-a-uuid']] as [$type, $id]) {
            try {
                RecordPaymentForm::draft($this->form($price, $gateway, ['payer_type' => $type, 'payer_id' => $id]), [$member]);
                $this->fail("Payer id [{$id}] must be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertSame(__('billing::billing.ui.record.invalid_payer'), $e->getMessage());
            }
        }

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($member), $this->form($price, $gateway, ['payer_type' => $member->getMorphClass(), 'payer_id' => 'abc']))
            ->assertHasFormErrors(['payer_id']);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_like_wildcards_in_the_payer_search_are_literal(): void
    {
        $farms = $this->member('100% Farms');
        $split = $this->member('Ama_Kofi');
        $this->member('Abena');
        $type = $farms->getMorphClass();

        $this->assertSame([$farms->id => '100% Farms'], PayerFields::search($type, '%'));
        $this->assertSame([$split->id => 'Ama_Kofi'], PayerFields::search($type, '_'));
        $this->assertSame([], PayerFields::search($type, '!'));
        $this->assertSame([], PayerFields::search($type, '\\'));
    }

    public function test_the_app_can_limit_which_records_may_pay(): void
    {
        $child = $this->member('Kwame');
        $allowed = $this->member('Abena');
        $hidden = $this->member('Abena Elsewhere');
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();
        $type = $allowed->getMorphClass();
        $seen = [];
        BillingPlugin::current()?->payerQueryUsing(function (Builder $query, string $class) use (&$seen, $hidden): Builder {
            $seen[] = $class;

            return $query->whereKeyNot($hidden->getKey());
        });

        $this->assertSame([$allowed->id => 'Abena'], PayerFields::search($type, 'abena'));
        $this->assertSame([Member::class], array_values(array_unique($seen)));

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($child), $this->form($price, $gateway, ['payer_type' => $type, 'payer_id' => $hidden->id]))
            ->assertHasFormErrors(['payer_id']);
        $this->assertSame(0, Payment::query()->count(), 'a tampered submit cannot name a hidden record');

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($child), $this->form($price, $gateway, ['payer_type' => $type, 'payer_id' => $allowed->id]))
            ->assertHasNoFormErrors();
        $this->assertTrue(Payment::query()->sole()->payer->is($allowed));
    }

    public function test_the_payer_must_pass_the_record_ability(): void
    {
        $child = $this->member('Kwame');
        $forbidden = $this->member('Abena Forbidden');
        $allowed = $this->member('Abena');
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();
        $type = $allowed->getMorphClass();
        Gate::define('billing.record', fn ($user, ?Model $billable = null): bool => $billable?->getKey() !== $forbidden->getKey());

        $this->assertSame([$allowed->id => 'Abena'], PayerFields::search($type, 'abena'));

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($child), $this->form($price, $gateway, ['payer_type' => $type, 'payer_id' => $forbidden->id]))
            ->assertHasFormErrors(['payer_id']);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_record_action_asks_the_ability_about_each_billable(): void
    {
        $member = $this->member('Kwame');
        $other = $this->member('Esi');
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();
        Gate::define('billing.record', fn ($user, ?Model $billable = null): bool => $billable === null || $billable->is($member));

        Livewire::test(ListMembers::class)
            ->assertActionVisible(TestAction::make('recordPayment')->table($member))
            ->assertActionHidden(TestAction::make('recordPayment')->table($other));

        $this->expectException(AuthorizationException::class);
        RecordPaymentForm::handle($this->form($price, $gateway), [$member, $other], Action::make('test'));
    }

    public function test_in_a_tenant_panel_the_payer_picker_only_sees_the_current_tenant(): void
    {
        $this->registerGuardians();
        $mine = Company::query()->create(['name' => 'Mine']);
        $theirs = Company::query()->create(['name' => 'Theirs']);
        $ours = Guardian::query()->create(['name' => 'Abena Ours', 'company_id' => $mine->id]);
        $other = Guardian::query()->create(['name' => 'Abena Theirs', 'company_id' => $theirs->id]);
        $type = $ours->getMorphClass();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();
        $member = $this->member();

        Filament::getCurrentOrDefaultPanel()?->tenant(Company::class);
        Filament::setTenant($mine, isQuiet: true);

        $this->assertSame([$ours->id => 'Abena Ours'], PayerFields::search($type, 'abena'));
        $this->assertTrue(RecordPaymentForm::draft($this->form($price, $gateway, ['payer_type' => $type, 'payer_id' => $ours->id]), [$member])->payer?->is($ours));

        $this->expectException(InvalidArgumentException::class);
        RecordPaymentForm::draft($this->form($price, $gateway, ['payer_type' => $type, 'payer_id' => $other->id]), [$member]);
    }

    public function test_in_a_tenant_panel_a_model_without_the_ownership_relationship_offers_nobody(): void
    {
        $this->registerGuardians();
        $mine = Company::query()->create(['name' => 'Mine']);
        $ours = Guardian::query()->create(['name' => 'Abena', 'company_id' => $mine->id]);
        $type = $ours->getMorphClass();

        Filament::getCurrentOrDefaultPanel()?->tenant(Company::class, ownershipRelationship: 'organisation');
        Filament::setTenant($mine, isQuiet: true);

        $this->assertSame([], PayerFields::search($type, 'abena'), 'no way to tell which tenant owns a guardian');

        BillingPlugin::current()?->payerQueryUsing(fn (Builder $query): Builder => $query->where('company_id', $mine->id));
        $this->assertSame([$ours->id => 'Abena'], PayerFields::search($type, 'abena'), 'the app scopes it explicitly');
    }

    public function test_outside_a_panel_no_payer_is_offered_and_a_submitted_one_is_refused(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway();
        Filament::setCurrentPanel(null);
        Filament::getPanel('admin')->default(false);
        app(PanelRegistry::class)->defaultPanel = null;

        $payerType = collect(RecordPaymentForm::components(null, group: false))
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'payer_type');
        $this->assertInstanceOf(Select::class, $payerType);
        $this->assertSame([], $payerType->getOptions());

        $this->assertNull(RecordPaymentForm::draft($this->form($price, $gateway), [$member])->payer);

        $this->expectException(InvalidArgumentException::class);
        RecordPaymentForm::draft($this->form($price, $gateway, ['payer_type' => $member->getMorphClass(), 'payer_id' => $member->id]), [$member]);
    }

    /**
     * @return iterable<string, array{0: callable(): Throwable}>
     */
    public static function listenerFailures(): iterable
    {
        yield 'generic exception' => [fn (): Throwable => new RuntimeException('SMS gateway down')];
        yield 'billing exception' => [fn (): Throwable => new BillingException('Listener refused')];
    }

    #[DataProvider('listenerFailures')]
    public function test_a_listener_failing_after_the_commit_keeps_the_payment_and_its_proof(callable $failure): void
    {
        Exceptions::fake();
        $failure = $failure();
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway(['settings' => ['require_proof' => true]]);
        Event::listen(PaymentRecorded::class, fn () => throw $failure);

        Livewire::test(ListMembers::class)
            ->mountAction(TestAction::make('recordPayment')->table($member))
            ->fillForm($this->form($price, $gateway, ['proof' => UploadedFile::fake()->image('receipt.jpg')]))
            ->callMountedAction()
            ->assertNotified(__('billing::billing.ui.record.recorded_with_errors'))
            ->assertActionNotMounted();

        $payment = Payment::query()->sole();
        $this->assertNotNull($payment->proof_path);
        $this->assertSame([$payment->proof_path], Storage::disk('local')->files(self::PROOFS), 'the proof of the saved payment stays');
        $this->assertTrue($member->isCovered());
        Exceptions::assertReported($failure::class);
        Exceptions::assertReportedCount(1);
    }

    public function test_a_proof_whose_content_is_not_an_image_or_pdf_is_refused(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway(['settings' => ['require_proof' => true]]);
        $png = UploadedFile::fake()->image('x.png');
        $spoofed = [
            UploadedFile::fake()->createWithContent('receipt.png', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->createWithContent('receipt.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('receipt.html', (string) file_get_contents($png->getPathname())),
        ];

        foreach ($spoofed as $file) {
            Livewire::test(ListMembers::class)
                ->callAction(TestAction::make('recordPayment')->table($member), $this->form($price, $gateway, ['proof' => $file]));
        }

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame([], Storage::disk('local')->files(self::PROOFS));
    }

    public function test_a_pdf_proof_is_stored_with_the_extension_of_its_content(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $gateway = $this->gateway(['settings' => ['require_proof' => true]]);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($member), $this->form($price, $gateway, [
                'proof' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
            ]))
            ->assertHasNoFormErrors();

        $this->assertStringEndsWith('.pdf', (string) Payment::query()->sole()->proof_path);
    }

    private function registerGuardians(): void
    {
        BillingPlugin::current()?->billables([Member::class => 'Members', Guardian::class => 'Guardians']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function form(PlanPrice $price, GatewayInstance $gateway, array $extra = []): array
    {
        return $extra + [
            'plan_id' => $price->plan_id,
            'price_id' => $price->id,
            'amount' => 15,
            'gateway_instance_id' => $gateway->id,
            'method' => 'cash',
            'paid_on' => '2026-03-10',
        ];
    }
}
