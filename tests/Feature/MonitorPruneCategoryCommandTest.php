<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

/**
 * laravel-monitor 308 (v0.63.0): `monitor:prune --category=` (repetível) —
 * mesma regra de categorização da action HTTP `clearData`
 * (`Support\MonitorCategories`/`DataPruner::pruneByCategory()`).
 */
class MonitorPruneCategoryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function monitor(?string $kind = null): Monitor
    {
        $monitor = Monitor::create(['data' => []]);

        if ($kind !== null) {
            MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => $kind]);
        }

        return $monitor;
    }

    protected function ageMonitor(Monitor $monitor, int $days): void
    {
        $monitor->timestamps = false;
        $monitor->updated_at = now()->subDays($days);
        $monitor->save();
    }

    public function test_category_option_restricts_the_delete_to_the_given_categories(): void
    {
        $bot = $this->monitor('bot');
        $this->ageMonitor($bot, 10);
        $human = $this->monitor('human');
        $this->ageMonitor($human, 10);

        $exitCode = Artisan::call('monitor:prune', ['--older-than-days' => 1, '--category' => ['bot']]);

        $this->assertSame(0, $exitCode);
        $this->assertNull(Monitor::find($bot->id));
        $this->assertNotNull(Monitor::find($human->id));
    }

    public function test_category_option_accepts_more_than_one_value(): void
    {
        $bot = $this->monitor('bot');
        $this->ageMonitor($bot, 10);
        $guest = $this->monitor('human');
        $this->ageMonitor($guest, 10);
        $clean = $this->monitor();
        $this->ageMonitor($clean, 10);

        $exitCode = Artisan::call('monitor:prune', ['--older-than-days' => 1, '--category' => ['bot', 'human_guest']]);

        $this->assertSame(0, $exitCode);
        $this->assertNull(Monitor::find($bot->id));
        $this->assertNull(Monitor::find($guest->id));
        $this->assertNotNull(Monitor::find($clean->id));
    }

    public function test_invalid_category_value_fails_without_deleting_anything(): void
    {
        $bot = $this->monitor('bot');
        $this->ageMonitor($bot, 10);

        $exitCode = Artisan::call('monitor:prune', ['--older-than-days' => 1, '--category' => ['not_a_real_category']]);

        $this->assertSame(1, $exitCode);
        $this->assertNotNull(Monitor::find($bot->id));
    }

    public function test_category_combined_with_only_blocked_fails_without_deleting_anything(): void
    {
        $bot = $this->monitor('bot');
        $this->ageMonitor($bot, 10);

        $exitCode = Artisan::call('monitor:prune', [
            '--older-than-days' => 1,
            '--category' => ['bot'],
            '--only-blocked' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertNotNull(Monitor::find($bot->id));
    }

    public function test_without_category_behaves_exactly_as_before_the_option_existed(): void
    {
        $bot = $this->monitor('bot');
        $this->ageMonitor($bot, 10);
        $human = $this->monitor('human');
        $this->ageMonitor($human, 10);

        $exitCode = Artisan::call('monitor:prune', ['--older-than-days' => 1]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, Monitor::count());
    }

    public function test_still_requires_older_than_days_even_with_category(): void
    {
        $exitCode = Artisan::call('monitor:prune', ['--category' => ['bot']]);

        $this->assertSame(1, $exitCode);
    }
}
