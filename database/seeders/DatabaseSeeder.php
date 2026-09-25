<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvitationFactory;
use Database\Seeders\ClientSeeder;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ClientSeeder::class);

        $clients = Client::all();
        $f = FakerFactory::create();
        InvitationFactory::new()->count($clients->count())->createMany(
            $clients->map(fn ($c) => ['client_id' => $c->id, 'purpose' => $f->randomElement(['initial', 'renewal'])])->toArray(),
        );
    }
}
