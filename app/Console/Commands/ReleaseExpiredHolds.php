<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Hold;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'holds:release-expired';
    protected $description = 'Release expired holds automatically';

    public function handle()
    {
        // Find all expired, unused holds
        $expiredHolds = Hold::where('expires_at', '<=', now())
                            ->where('used', false)
                            ->get();

        foreach ($expiredHolds as $hold) {
            $hold->delete();

            Log::info("Expired hold released", [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id
            ]);
        }

        return Command::SUCCESS;
    }
}
