<?php

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Models\GameDetail;
use App\Services\ScreenScraper\GameScraper;
use App\Services\ScreenScraper\ScrapeResult;
use App\Services\ScreenScraper\ScreenScraperClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewGame extends ViewRecord
{
    protected static string $resource = GameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->setMediaDonorAction(),
            $this->setMediaRegionAction(),
        ];
    }

    /** Another ScreenScraper sheet, for the types of pictures the game has none of (D99). */
    public function setMediaDonorAction(): Action
    {
        return Action::make('setMediaDonor')
            ->label(__('Media donor'))
            ->icon(Heroicon::OutlinedPhoto)
            ->color('gray')
            ->modalDescription(__('Another game\'s ScreenScraper sheet (its number in the address of its page). Only the types of pictures this game has none of are taken from it: marquee, flyers, logos, screenshot, title. ScreenScraper is asked again at once.'))
            ->fillForm(fn (): array => ['media_donor_id' => $this->detail()?->media_donor_id])
            ->schema([
                TextInput::make('media_donor_id')
                    ->label(__('ScreenScraper sheet'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(2147483647)
                    ->placeholder(__('None')),
            ])
            ->action(function (array $data): void {
                $donor = filled($data['media_donor_id'] ?? null) ? (int) $data['media_donor_id'] : null;
                $this->choose(['media_donor_id' => $donor]);
            });
    }

    /** The region whose pictures come first for this game (D99). */
    public function setMediaRegionAction(): Action
    {
        return Action::make('setMediaRegion')
            ->label(__('Priority region'))
            ->icon(Heroicon::OutlinedGlobeEuropeAfrica)
            ->color('gray')
            ->modalDescription(__('Its pictures come first, then the usual order: Europe, world, USA, Japan. ScreenScraper is asked again at once.'))
            ->fillForm(fn (): array => ['media_region' => $this->detail()?->media_region])
            ->schema([
                Select::make('media_region')
                    ->label(__('Region'))
                    ->options(array_map(fn (string $name): string => __($name), ScreenScraperClient::PRIORITY_REGIONS))
                    ->placeholder(__('None: the usual order')),
            ])
            ->action(fn (array $data) => $this->choose(['media_region' => filled($data['media_region'] ?? null) ? (string) $data['media_region'] : null]));
    }

    /**
     * Keeps the choice, then asks ScreenScraper again: the API only keeps the pictures it chose,
     * so another choice needs the sheet again.
     *
     * @param  array<string, int|string|null>  $choice
     */
    private function choose(array $choice): void
    {
        $game = $this->game();
        $detail = $this->detail();
        if ($detail === null) {
            GameDetail::query()->create(['game_id' => $game->id, 'found' => false, 'scraped_at' => now()->subYear(), ...$choice]);
        } else {
            $detail->update($choice);
        }

        if (! app(ScreenScraperClient::class)->isConfigured()) {
            Notification::make()->title(__('Saved; ScreenScraper is not configured, the pictures change at the next scrape.'))->warning()->send();

            return;
        }
        $result = app(GameScraper::class)->scrape($game);
        $game->refresh();
        $notification = Notification::make()->title(match ($result->status) {
            ScrapeResult::FOUND => __('Saved, pictures asked again'),
            ScrapeResult::NOT_FOUND => __('Saved; ScreenScraper does not know this game, only the media donor\'s pictures are taken'),
            default => __('Saved; ScreenScraper could not be asked, the pictures change at the next scrape'),
        })->body($result->message);
        in_array($result->status, [ScrapeResult::FOUND, ScrapeResult::NOT_FOUND], true) && $result->message === null
            ? $notification->success()->send()
            : $notification->warning()->send();
    }

    private function game(): Game
    {
        $record = $this->getRecord();
        assert($record instanceof Game);

        return $record;
    }

    private function detail(): ?GameDetail
    {
        return GameDetail::query()->where('game_id', $this->game()->id)->first();
    }
}
