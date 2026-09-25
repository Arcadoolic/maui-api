<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MachineBinding
{
    public function handle(Request $request, Closure $next): void
    {
        /** @var Client|null $client */
        $client = $request->user();

        if ($client === null) {
            throw new HttpException(statusCode: 401, message: 'Unauthenticated.', code: 4000);
        }

        if ($client->status !== 'active') {
            throw new AccessDeniedHttpException(message: 'This client has been disabled.', code: 4001);
        }

        $fingerprint = $request->header('X-Maui-Machine');

        if ($fingerprint === null) {
            throw new HttpException(statusCode: 400, message: 'Missing X-Maui-Machine header.', code: 4100);
        }

        $fingerprintHash = hash('sha256', $fingerprint);

        if ($client->machine_fingerprint_hash === null) {
            $this->bindMachine($client, $fingerprintHash);
        }

        if ($client->machine_fingerprint_hash === $fingerprintHash) {
            $next($request);
        } else {
            throw new AccessDeniedHttpException(message: 'Machine binding mismatch. These credentials were issued for a different cabinet.', code: 4101);
        }
    }

    /** @param Client|User $client */
    private function bindMachine($client, string $fingerprintHash): void
    {
        DB::transaction(function () use ($client, $fingerprintHash) {
            $updated = DB::table('clients')
                ->where('id', $client->getKey())
                ->whereNull('machine_fingerprint_hash')
                ->update([
                    'machine_fingerprint_hash' => $fingerprintHash,
                    'bound_at' => now(),
                ]);

            if ($updated === 0) {
                $fresh = DB::table('clients')
                    ->where('id', $client->getKey())
                    ->first();

                if ($fresh?->machine_fingerprint_hash !== $fingerprintHash) {
                    throw new AccessDeniedHttpException(message: 'Machine binding mismatch. These credentials were issued for a different cabinet.', code: 4101);
                }
            }
        });
    }
}
