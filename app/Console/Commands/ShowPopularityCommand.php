<?php

namespace App\Console\Commands;

use App\Enums\PopularityLabel;
use App\Models\Game;
use App\Services\Popularity\GamePopularity;
use App\Services\Popularity\Popularity;
use Illuminate\Console\Command;

/**
 * Prints the games by popularity with what it is made of, to tune
 * config/hiscores.php on real data (docs/DECISIONS.md D76). Changes nothing.
 */
final class ShowPopularityCommand extends Command
{
    protected $signature = 'hiscores:popularity
        {--limit=30 : Games shown, the most popular first}
        {--label= : Only the games with this label, or "none"}
        {--min-votes= : Votes from which a game gets a label about its votes}
        {--opinion-weight= : Weight of the opinion, 0 to 1; the activity gets the rest}
        {--prior-votes= : Imaginary votes at the average added to each game}
        {--activity-half= : Activity worth half the scale}
        {--hit-share= : Share of the liked games, the most played, that are hits}
        {--hidden-gem-share= : Share of the liked games, the least played, that are hidden gems}
        {--addictive-share= : Share of the games played, the most played, that can be addictive}';

    protected $description = 'Show the games by popularity, with the settings in force or the ones given';

    public function handle(Popularity $popularity): int
    {
        $rules = Popularity::rules();
        if ($this->option('min-votes') !== null) {
            $rules['min_votes'] = max(1, (int) $this->option('min-votes'));
        }
        if ($this->option('opinion-weight') !== null) {
            $rules['opinion_weight'] = min(1.0, max(0.0, (float) $this->option('opinion-weight')));
            $rules['activity_weight'] = 1.0 - $rules['opinion_weight'];
        }
        if ($this->option('prior-votes') !== null) {
            $rules['prior_votes'] = max(0.0, (float) $this->option('prior-votes'));
        }
        if ($this->option('activity-half') !== null) {
            $rules['activity_half'] = max(0.01, (float) $this->option('activity-half'));
        }
        foreach (['hit_share', 'hidden_gem_share', 'addictive_share'] as $share) {
            $option = $this->option(str_replace('_', '-', $share));
            if ($option !== null) {
                $rules[$share] = min(1.0, max(0.0, (float) $option));
            }
        }

        $label = $this->option('label');
        if (is_string($label) && $label !== 'none' && PopularityLabel::tryFrom($label) === null) {
            $this->error('Unknown label "'.$label.'": '.implode(', ', array_column(PopularityLabel::cases(), 'value')).' or none.');

            return self::FAILURE;
        }

        $games = $popularity->all($rules);
        $counts = $games->countBy(fn (GamePopularity $game): string => $game->label->value ?? 'none');
        $shown = $games
            ->when(is_string($label), fn ($games) => $games->filter(fn (GamePopularity $game): bool => ($game->label->value ?? 'none') === $label))
            ->take(max(1, (int) $this->option('limit')));
        $romnames = Game::query()->whereIn('id', $shown->keys())->pluck('romname', 'id');

        $this->line(sprintf(
            'Opinion %.0f%%, activity %.0f%%; %s imaginary votes; labels from %d votes; activity half at %s; hits %.0f%%, hidden gems %.0f%% of the liked games, addictive %.0f%% of the games played.',
            100 * $rules['opinion_weight'] / max($rules['opinion_weight'] + $rules['activity_weight'], PHP_FLOAT_EPSILON),
            100 * $rules['activity_weight'] / max($rules['opinion_weight'] + $rules['activity_weight'], PHP_FLOAT_EPSILON),
            rtrim(rtrim(number_format($rules['prior_votes'], 2, '.', ''), '0'), '.'),
            $rules['min_votes'],
            rtrim(rtrim(number_format($rules['activity_half'], 2, '.', ''), '0'), '.'),
            100 * $rules['hit_share'],
            100 * $rules['hidden_gem_share'],
            100 * $rules['addictive_share'],
        ));
        $this->line($games->count().' games: '.collect([...array_column(PopularityLabel::cases(), 'value'), 'none'])
            ->map(fn (string $name): string => ($counts[$name] ?? 0).' '.$name)->implode(', ').'.');
        $this->table(
            ['#', 'Game', 'Index', 'Label', 'Up', 'Down', 'By players', 'Opinion', 'Cabinets', 'Plays', 'Recent', 'Players', 'Activity'],
            $shown->values()->map(fn (GamePopularity $game, int $position): array => [
                $position + 1,
                $romnames[$game->gameId] ?? '?',
                number_format($game->index, 1),
                $game->label->value ?? '',
                $game->thumbsUp,
                $game->thumbsDown,
                $game->playerVotes,
                number_format($game->opinion, 2),
                $game->cabinets,
                $game->plays,
                $game->recentCabinets,
                $game->rankedPlayers,
                number_format($game->activity, 2),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
