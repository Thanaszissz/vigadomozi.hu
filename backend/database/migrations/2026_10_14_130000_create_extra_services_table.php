<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Egyéb szolgáltatások (nem mozijegy) árai, a Beállítások → Egyéb szolgáltatások fülön szerkeszthetők
        Schema::create('extra_services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach ([
            ['Sárkányvár – felnőtt', 400],
            ['Sárkányvár – gyerek', 300],
            ['Néprajzi gyűjtemények jegye', 500],
            ['Dolgozó jegy', 0],
            ['Nyereményjegy', 0],
            ['Élménykártya (6 000 Ft-os)', 6000],
            ['Élménykártya (10 000 Ft-os)', 10000],
        ] as $i => [$name, $price]) {
            DB::table('extra_services')->insert([
                'name' => $name,
                'price' => $price,
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_services');
    }
};
