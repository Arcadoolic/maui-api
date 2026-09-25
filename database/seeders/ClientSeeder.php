<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Invitation;
use Database\Factories\ClientFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clients = Client::factory()->count(5)->active()->create();
        Client::factory()->count(2)->disabled()->create();
        $bound = Client::factory()->count(3)->bound()->active()->create();

        Invitation::factory()->count($clients->count() + $bound->count())->create();
    }
}
