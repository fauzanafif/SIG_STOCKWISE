<?php

namespace Database\Factories;

use App\Models\Ppb;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ppb> */
class PpbFactory extends Factory
{
    protected $model = Ppb::class;

    public function definition(): array
    {
        return ['number' => 'PPB/NA/26/IX/'.$this->faker->unique()->numberBetween(1, 999), 'prefix' => 'NA', 'year' => 26, 'month' => 9, 'sequence' => $this->faker->unique()->numberBetween(1, 999), 'date' => now()->toDateString(), 'site_id' => Site::factory(), 'status' => 'DRAFT'];
    }
}
