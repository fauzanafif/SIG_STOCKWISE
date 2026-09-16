<?php

namespace Database\Factories;

use App\Models\PurchaseProposal;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseProposal> */
class PurchaseProposalFactory extends Factory
{
    protected $model = PurchaseProposal::class;

    public function definition(): array
    {
        return ['number' => 'UPB/NA/26/IX/'.$this->faker->unique()->numberBetween(1, 999), 'prefix' => 'NA', 'year' => 26, 'month' => 9, 'sequence' => $this->faker->unique()->numberBetween(1, 999), 'date' => now()->toDateString(), 'site_id' => Site::factory(), 'status' => 'DRAFT'];
    }
}
