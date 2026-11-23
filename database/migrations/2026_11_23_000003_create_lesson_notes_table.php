<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملاحظات الطالب والعلامات المرجعية داخل الدروس.
 *
 * غرض مزدوج في جدول واحد:
 *   - `type = note`     : ملاحظة نصية حرّة (قد تُربط بوقت في الفيديو أو لا).
 *   - `type = bookmark` : علامة مرجعية زمنية («احفظ هذه اللحظة»).
 *
 * الخصوصية: الصف ملكٌ لطالب واحد (`user_id`) ولا يُقصد به أي مشاركة. لا يقرأه
 * إلا صاحبه. لا يُخزَّن أي جزء من الفيديو — `position_seconds` مجرد مرجع زمني،
 * و`duration_seconds` لقطة لكشف استبدال الفيديو بمدة مختلفة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('course_id');
            $table->uuid('lesson_id');
            $table->uuid('video_id')->nullable();
            $table->string('type', 16)->default('note');
            $table->text('body')->nullable();
            // مرجع زمني فقط — لا نخزّن الفيديو ولا أي محتوى منه.
            $table->integer('position_seconds')->nullable();
            // مدة الفيديو وقت الحفظ: لقطة تكشف استبدال الفيديو بمدة مختلفة.
            $table->integer('duration_seconds')->nullable();
            $table->timestampsTz();

            // استعلام القائمة الشخصية: (المالك + الدرس) و(المالك + الكورس).
            $table->index(['user_id', 'lesson_id']);
            $table->index(['user_id', 'course_id']);
            $table->index(['user_id', 'updated_at']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
            // حذف الفيديو نهائياً لا يحذف ملاحظة الطالب؛ تبقى الملاحظة نصّية.
            $table->foreign('video_id')->references('id')->on('video_contents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_notes');
    }
};
