<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Automation\Models;

use App\Domain\Alerts\Models\NotificationProfile;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationProfile>
 */
class AutomationNotificationProfileFactory extends Factory
{
    protected $model = NotificationProfile::class;

    public function configure(): static
    {
        return $this->afterCreating(function (NotificationProfile $profile): void {
            if ($profile->users()->exists()) {
                return;
            }

            $user = User::factory()->create([
                'email' => $profile->channel === 'email' ? fake()->unique()->safeEmail() : fake()->unique()->safeEmail(),
                'phone_number' => $profile->channel === 'sms'
                    ? '+9477'.fake()->unique()->numerify('#######')
                    : null,
            ]);

            $user->organizations()->syncWithoutDetaching([$profile->organization_id]);
            $profile->users()->syncWithoutDetaching([$user->id]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $channel = $this->faker->randomElement(['sms', 'email']);

        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->unique()->words(3, true),
            'channel' => $channel,
            'enabled' => true,
            'recipients' => $channel === 'sms'
                ? ['94771234567']
                : [$this->faker->safeEmail()],
            'subject' => $channel === 'email' ? $this->faker->sentence() : null,
            'body' => $this->faker->sentence(10),
            'mask' => $channel === 'sms' ? 'ELWIDS' : null,
            'campaign_name' => $channel === 'sms' ? 'Cold Room Monitoring' : null,
            'legacy_metadata' => null,
        ];
    }

    public function sms(): static
    {
        return $this->state(fn (): array => [
            'channel' => 'sms',
            'recipients' => ['94771234567'],
            'subject' => null,
            'mask' => 'ELWIDS',
            'campaign_name' => 'Cold Room Monitoring',
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (): array => [
            'channel' => 'email',
            'recipients' => [$this->faker->safeEmail()],
            'subject' => $this->faker->sentence(),
            'mask' => null,
            'campaign_name' => null,
        ]);
    }
}
