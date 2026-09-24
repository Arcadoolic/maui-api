<?php

namespace App\Filament\Widgets;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Fleet status on the dashboard (docs/PLAN.md 1.5).
 */
class CabinetsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $cabinets = Client::query()->where('type', ClientType::Maui);

        $online = (clone $cabinets)
            ->where('status', ClientStatus::Active)
            ->where('last_heartbeat_at', '>', now()->subMinutes(Client::ONLINE_THRESHOLD_MINUTES))
            ->count();

        return [
            Stat::make(__('Cabinets'), (clone $cabinets)->count()),
            Stat::make(__('Online now'), $online)->color('success'),
            Stat::make(__('Disabled'), (clone $cabinets)->where('status', ClientStatus::Disabled)->count())->color('danger'),
        ];
    }
}
