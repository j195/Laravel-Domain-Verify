<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // blacklist | provider
            $table->string('source', 16)->default('single'); // single | bulk
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 24)->default('queued'); // queued | running | completed | failed
            $table->string('original_filename')->nullable();
            $table->string('dkim_selector')->nullable();
            $table->boolean('include_all_records')->default(false);
            $table->timestamps();
        });

        Schema::create('check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_batch_id')->constrained()->cascadeOnDelete();
            $table->string('input');
            $table->string('domain')->nullable();
            $table->string('status', 24)->default('queued'); // queued | checking | completed | failed
            $table->string('result_label')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['check_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_items');
        Schema::dropIfExists('check_batches');
    }
};
