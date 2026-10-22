<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tickets')) return;

        if (!Schema::hasColumn('tickets', 'is_incident')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->boolean('is_incident')->default(false)->after('status');
            });
        }

        if (!Schema::hasColumn('tickets', 'incident_severity')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('incident_severity')->nullable()->after('is_incident');
            });
        }

        if (!Schema::hasColumn('tickets', 'detected_at')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->timestamp('detected_at')->nullable()->after('incident_severity');
            });
        }

        if (!Schema::hasColumn('tickets', 'linked_alert_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('linked_alert_id')->nullable()->after('detected_at');
            });
        }

        if (!Schema::hasColumn('tickets', 'linked_finding_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('linked_finding_id')->nullable()->after('linked_alert_id');
            });
        }

        if (!Schema::hasColumn('tickets', 'lessons_learned')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->text('lessons_learned')->nullable()->after('linked_finding_id');
            });
        }

        if (!Schema::hasColumn('tickets', 'root_cause')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->text('root_cause')->nullable()->after('lessons_learned');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tickets')) return;

        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'root_cause')) $table->dropColumn('root_cause');
            if (Schema::hasColumn('tickets', 'lessons_learned')) $table->dropColumn('lessons_learned');
            if (Schema::hasColumn('tickets', 'linked_finding_id')) $table->dropColumn('linked_finding_id');
            if (Schema::hasColumn('tickets', 'linked_alert_id')) $table->dropColumn('linked_alert_id');
            if (Schema::hasColumn('tickets', 'detected_at')) $table->dropColumn('detected_at');
            if (Schema::hasColumn('tickets', 'incident_severity')) $table->dropColumn('incident_severity');
            if (Schema::hasColumn('tickets', 'is_incident')) $table->dropColumn('is_incident');
        });
    }
};
