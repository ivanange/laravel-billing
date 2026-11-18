<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Filament;

use Ivanange\Billing\Filament\Resources\GatewayInstances\GatewayInstanceResource;
use Ivanange\Billing\Filament\Resources\GatewayInstances\Pages\EditGatewayInstance;
use Ivanange\Billing\Tests\FilamentTestCase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class GatewayInstanceResourceTest extends FilamentTestCase
{
    public function test_the_edit_page_never_shows_stored_credentials_and_blank_fields_keep_them(): void
    {
        $instance = $this->notchpay(credentials: ['private_key' => 'sk_test_PRIVATE123', 'hash_key' => 'hsk_test_HASH456']);
        $secrets = ['PRIVATE123', 'HASH456', 'pk_test_cm'];

        $this->assertSecretsAbsent($secrets, (string) $this->get(GatewayInstanceResource::getUrl('edit', ['record' => $instance]))->getContent());

        $page = Livewire::test(EditGatewayInstance::class, ['record' => $instance->id]);
        $this->assertSecretsAbsentFrom($secrets, $page);

        $page->fillForm(['name' => 'NotchPay Douala'])->call('save')->assertHasNoFormErrors();
        $this->assertEquals(
            ['public_key' => 'pk_test_cm', 'private_key' => 'sk_test_PRIVATE123', 'hash_key' => 'hsk_test_HASH456'],
            $instance->refresh()->credentials,
        );
        $this->assertSame('NotchPay Douala', $instance->name);
        $this->assertSecretsAbsentFrom($secrets, $page);

        $page->fillForm(['credentials.private_key' => 'sk_test_REPLACED789'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('sk_test_REPLACED789', $instance->refresh()->credentials['private_key']);
        $this->assertSame('hsk_test_HASH456', $instance->credentials['hash_key'], 'only the filled field is replaced');

        $this->assertSecretsAbsentFrom(['REPLACED789', 'HASH456'], Livewire::test(EditGatewayInstance::class, ['record' => $instance->id]));
    }

    /**
     * @param  list<string>  $secrets
     */
    private function assertSecretsAbsentFrom(array $secrets, Testable $page): void
    {
        $this->assertSecretsAbsent($secrets, (string) json_encode($page->get('data')));
        $this->assertSecretsAbsent($secrets, $page->html());
    }

    /**
     * @param  list<string>  $secrets
     */
    private function assertSecretsAbsent(array $secrets, string $haystack): void
    {
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
    }
}
