<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_enrollments_tenant_id');
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_lesson_progress_tenant_id');
        });

        Schema::table('lesson_contents', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_lesson_contents_tenant_id');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_sections_tenant_id');
        });

        Schema::table('question_options', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_question_options_tenant_id');
        });

        Schema::table('text_contents', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_text_contents_tenant_id');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_exam_attempts_tenant_id');
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_exam_answers_tenant_id');
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_exam_questions_tenant_id');
            $table->index('exam_section_id', 'idx_exam_questions_exam_section');
        });

        Schema::table('exam_sections', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_exam_sections_tenant_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('tenant_subscription_id', 'idx_payments_subscription_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index('payment_id', 'idx_invoices_payment_id');
            $table->index('tenant_subscription_id', 'idx_invoices_subscription_id');
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->index('plan_id', 'idx_tenant_subs_plan_id');
        });

        Schema::table('activation_cards', function (Blueprint $table) {
            $table->index('student_id', 'idx_activation_cards_student_id');
        });

        Schema::table('card_redemptions', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_card_redemptions_tenant_id');
            $table->index('course_id', 'idx_card_redemptions_course_id');
            $table->index('enrollment_id', 'idx_card_redemptions_enrollment_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index('sender_id', 'idx_notifications_sender_id');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->index('section_id', 'idx_questions_section_id');
        });

        Schema::table('card_redemptions', function (Blueprint $table) {
            $table->string('status', 20)->default('used');
        });

        // Postgres-specific indexes/constraints — skipped on other drivers
        // (e.g. SQLite test environments).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX ux_card_redemptions_single_use ON card_redemptions(card_id) WHERE status = \'used\'');

            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            DB::statement('CREATE INDEX idx_courses_title_trgm ON courses USING gin (title gin_trgm_ops)');
            DB::statement('CREATE INDEX idx_courses_description_trgm ON courses USING gin (description gin_trgm_ops)');
            DB::statement('CREATE INDEX idx_courses_tags_trgm ON courses USING gin (tags gin_trgm_ops)');
            DB::statement('CREATE INDEX idx_lessons_title_trgm ON lessons USING gin (title gin_trgm_ops)');
            DB::statement('CREATE INDEX idx_questions_text_trgm ON questions USING gin (question_text gin_trgm_ops)');

            DB::statement('CREATE INDEX idx_courses_active ON courses(tenant_id, is_published) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_lessons_active ON lessons(tenant_id, is_published) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_sections_active ON sections(tenant_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_lesson_contents_active ON lesson_contents(tenant_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_questions_active ON questions(tenant_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_exams_active ON exams(tenant_id) WHERE deleted_at IS NULL');

            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount_positive CHECK (amount >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_status CHECK (status IN (\'pending\', \'completed\', \'failed\', \'refunded\', \'paid\'))');
            DB::statement('ALTER TABLE courses ADD CONSTRAINT chk_courses_price_positive CHECK (price IS NULL OR price >= 0)');
            DB::statement('ALTER TABLE activation_cards ADD CONSTRAINT chk_cards_status CHECK (status IN (\'generated\', \'redeemed\', \'expired\', \'cancelled\'))');
            DB::statement('ALTER TABLE card_redemptions ADD CONSTRAINT chk_card_redemptions_status CHECK (status IN (\'used\', \'cancelled\'))');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_super_admin']);
            $table->dropIndex(['last_login_at']);
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index(['user_id', 'lesson_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('is_super_admin');
            $table->index('last_login_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE card_redemptions DROP CONSTRAINT chk_card_redemptions_status');
            DB::statement('ALTER TABLE activation_cards DROP CONSTRAINT chk_cards_status');
            DB::statement('ALTER TABLE courses DROP CONSTRAINT chk_courses_price_positive');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_status');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_amount_positive');

            DB::statement('DROP INDEX IF EXISTS idx_exams_active');
            DB::statement('DROP INDEX IF EXISTS idx_questions_active');
            DB::statement('DROP INDEX IF EXISTS idx_lesson_contents_active');
            DB::statement('DROP INDEX IF EXISTS idx_sections_active');
            DB::statement('DROP INDEX IF EXISTS idx_lessons_active');
            DB::statement('DROP INDEX IF EXISTS idx_courses_active');
            DB::statement('DROP INDEX IF EXISTS idx_questions_text_trgm');
            DB::statement('DROP INDEX IF EXISTS idx_lessons_title_trgm');
            DB::statement('DROP INDEX IF EXISTS idx_courses_tags_trgm');
            DB::statement('DROP INDEX IF EXISTS idx_courses_description_trgm');
            DB::statement('DROP INDEX IF EXISTS idx_courses_title_trgm');

            DB::statement('DROP INDEX IF EXISTS ux_card_redemptions_single_use');
        }

        Schema::table('card_redemptions', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex('idx_questions_section_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_sender_id');
        });

        Schema::table('card_redemptions', function (Blueprint $table) {
            $table->dropIndex('idx_card_redemptions_enrollment_id');
            $table->dropIndex('idx_card_redemptions_course_id');
            $table->dropIndex('idx_card_redemptions_tenant_id');
        });

        Schema::table('activation_cards', function (Blueprint $table) {
            $table->dropIndex('idx_activation_cards_student_id');
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->dropIndex('idx_tenant_subs_plan_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_subscription_id');
            $table->dropIndex('idx_invoices_payment_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_subscription_id');
        });

        Schema::table('exam_sections', function (Blueprint $table) {
            $table->dropIndex('idx_exam_sections_tenant_id');
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropIndex('idx_exam_questions_exam_section');
            $table->dropIndex('idx_exam_questions_tenant_id');
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropIndex('idx_exam_answers_tenant_id');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('idx_exam_attempts_tenant_id');
        });

        Schema::table('text_contents', function (Blueprint $table) {
            $table->dropIndex('idx_text_contents_tenant_id');
        });

        Schema::table('question_options', function (Blueprint $table) {
            $table->dropIndex('idx_question_options_tenant_id');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex('idx_sections_tenant_id');
        });

        Schema::table('lesson_contents', function (Blueprint $table) {
            $table->dropIndex('idx_lesson_contents_tenant_id');
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex('idx_lesson_progress_tenant_id');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('idx_enrollments_tenant_id');
        });
    }
};
