<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 50)->nullable()->unique();
            $table->string('role', 24)->nullable();
            $table->boolean('is_active')->default(false);
            $table->index(['role', 'is_active'], 'users_role_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_role_active_idx');
            $table->dropUnique('users_username_unique');
            $table->dropColumn(['username', 'role', 'is_active']);
        });
    }
};
