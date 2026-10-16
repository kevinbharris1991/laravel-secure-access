<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SecureAccess\SecureAccess;

return new class extends Migration
{
    public function up(): void
    {
        $model = SecureAccess::newUserQuery()->getModel();
        $schema = $model->getConnection()->getSchemaBuilder();

        $schema->table($model->getTable(), function (Blueprint $table) use ($schema, $model) {
            if (! $schema->hasColumn($model->getTable(), 'date_of_birth')) {
                $table->date('date_of_birth')->nullable();
            }

            if (! $schema->hasColumn($model->getTable(), 'gender')) {
                $table->string('gender')->nullable();
            }
        });

        $schema->create('secure_access_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->date('date_of_birth');
            $table->string('email');
            $table->string('gender');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $model = SecureAccess::newUserQuery()->getModel();
        $model->getConnection()->getSchemaBuilder()->dropIfExists('secure_access_registrations');

        // Profile columns are kept because they may have existed before this migration ran.
    }
};
