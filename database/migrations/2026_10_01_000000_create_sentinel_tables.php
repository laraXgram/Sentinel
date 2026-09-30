<?php

use LaraGram\Database\Migrations\Migration;
use LaraGram\Database\Schema\Blueprint;
use LaraGram\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return config('sentinel.storage.database.connection');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('sentinel_entries', function (Blueprint $table) {
            $table->bigIncrements('sequence');
            $table->uuid('uuid');
            $table->uuid('batch_id');
            $table->string('family_hash')->nullable();
            $table->boolean('should_display_on_index')->default(true);
            $table->string('type', 32);
            $table->string('connection', 64)->nullable();
            $table->longText('content');
            $table->dateTime('created_at')->nullable();

            $table->unique('uuid');
            $table->index('batch_id');
            $table->index('family_hash');
            $table->index('created_at');
            $table->index(['type', 'should_display_on_index']);
        });

        $schema->create('sentinel_entries_tags', function (Blueprint $table) {
            $table->uuid('entry_uuid');
            $table->string('tag');

            $table->primary(['entry_uuid', 'tag']);
            $table->index('tag');

            $table->foreign('entry_uuid')
                ->references('uuid')
                ->on('sentinel_entries')
                ->onDelete('cascade');
        });

        $schema->create('sentinel_monitoring', function (Blueprint $table) {
            $table->string('tag')->primary();
        });

        $schema->create('sentinel_aggregates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bucket');
            $table->unsignedMediumInteger('period');
            $table->string('type', 64);
            $table->string('connection', 64)->default('');
            $table->mediumText('key');
            $table->char('key_hash', 32);
            $table->string('aggregate', 16);
            $table->decimal('value', 20, 2);
            $table->unsignedInteger('count')->nullable();

            $table->unique(['bucket', 'period', 'type', 'connection', 'aggregate', 'key_hash'], 'sentinel_aggregates_unique');
            $table->index(['period', 'bucket']);
            $table->index('type');
            $table->index(['period', 'type', 'aggregate', 'bucket'], 'sentinel_aggregates_lookup');
        });

        $schema->create('sentinel_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type', 64);
            $table->mediumText('key');
            $table->char('key_hash', 32);
            $table->longText('value');

            $table->unique(['type', 'key_hash']);
            $table->index('timestamp');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('sentinel_entries_tags');
        $schema->dropIfExists('sentinel_entries');
        $schema->dropIfExists('sentinel_monitoring');
        $schema->dropIfExists('sentinel_aggregates');
        $schema->dropIfExists('sentinel_values');
    }
};
