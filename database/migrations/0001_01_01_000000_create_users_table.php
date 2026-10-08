<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts of members (natural or legal persons) and internal staff.
     * Member business capabilities come from packs, not from roles.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS member_number_seq START 1');

        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 20);
            $table->string('person_type', 20)->nullable();
            $table->string('member_number', 20)->nullable()->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('preferred_payment_method', 30)->nullable();
            $table->timestampTz('commitment_accepted_at')->nullable();
            $table->string('commitment_version', 40)->nullable();
            $table->string('signed_place', 120)->nullable();
            $table->date('signed_on')->nullable();
            $table->string('status', 40)->index();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->string('status_before_suspension', 40)->nullable();
            $table->rememberToken();
            $table->timestampsTz();

            $table->index(['kind', 'status']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_kind_check CHECK (kind IN ('member', 'staff'))");
        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_member_person_type_check CHECK (
                (kind = 'member' AND person_type IN ('natural', 'legal'))
                OR (kind = 'staff' AND person_type IS NULL)
            )
        SQL);
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase_check CHECK (email = lower(email))');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
        DB::statement('DROP SEQUENCE IF EXISTS member_number_seq');
    }
};
