<?php

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Abilities are copied into each token when it is issued: the cabinet tokens
// issued before `players` existed get it here, so that enrolled cabinets
// manage their players without a new token (docs/DECISIONS.md D48).
return new class extends Migration
{
    private const ABILITY = 'players';

    public function up(): void
    {
        $this->rewriteAbilities(fn (array $abilities): array => in_array(self::ABILITY, $abilities, true)
            ? $abilities
            : [...$abilities, self::ABILITY]);
    }

    public function down(): void
    {
        $this->rewriteAbilities(fn (array $abilities): array => array_values(
            array_filter($abilities, fn (string $ability): bool => $ability !== self::ABILITY),
        ));
    }

    /**
     * @param  Closure(list<string>): list<string>  $rewrite
     */
    private function rewriteAbilities(Closure $rewrite): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new Client)->getMorphClass())
            ->whereIn('tokenable_id', DB::table('clients')->select('id')->where('type', ClientType::Maui->value))
            ->orderBy('id')
            ->each(function (object $token) use ($rewrite): void {
                /** @var list<string> $abilities */
                $abilities = json_decode($token->abilities ?? '[]', true) ?: [];

                DB::table('personal_access_tokens')
                    ->where('id', $token->id)
                    ->update(['abilities' => json_encode($rewrite($abilities))]);
            });
    }
};
