<?php

use App\Http\Controllers\Api\ActivationCardController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminLiveProviderController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\Api\Auth\MobileAuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BiController;
use App\Http\Controllers\Api\CommunityCommentController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\CommunityGroupController;
use App\Http\Controllers\Api\CommunityLikeController;
use App\Http\Controllers\Api\CommunityThreadController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\ExamAttemptController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamSectionController;
use App\Http\Controllers\Api\FileDownloadController;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\FreemiusCheckoutController;
use App\Http\Controllers\Api\FreemiusWebhookController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LessonContentController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\LessonProgressController;
use App\Http\Controllers\Api\LiveSessionController;
use App\Http\Controllers\Api\MediaAdminController;
use App\Http\Controllers\Api\MediaAssetController;
use App\Http\Controllers\Api\MediaWebhookController;
use App\Http\Controllers\Api\MobileAppController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\MonitoringIngestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentInitiateController;
use App\Http\Controllers\Api\PlanFeatureController;
use App\Http\Controllers\Api\PlanRecommenderController;
use App\Http\Controllers\Api\PlatformSettingController;
use App\Http\Controllers\Api\PublicBookingController;
use App\Http\Controllers\Api\PublicSiteController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\QuestionOptionController;
use App\Http\Controllers\Api\QuranTeacherRequestController;
use App\Http\Controllers\Api\ScheduledLessonController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\StudentAttemptController;
use App\Http\Controllers\Api\StudentCertificateController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\TeacherAnalyticsController;
use App\Http\Controllers\Api\TeacherAvailabilityController;
use App\Http\Controllers\Api\TeacherBlogController;
use App\Http\Controllers\Api\TeacherBookingController;
use App\Http\Controllers\Api\TeacherDeviceController;
use App\Http\Controllers\Api\TeacherDomainController;
use App\Http\Controllers\Api\TeacherFilePolicyController;
use App\Http\Controllers\Api\TeacherPlanController;
use App\Http\Controllers\Api\TeacherServiceController;
use App\Http\Controllers\Api\TeacherSiteController;
use App\Http\Controllers\Api\TeacherStaffController;
use App\Http\Controllers\Api\TeacherStudentAppController;
use App\Http\Controllers\Api\TeacherVideoSecurityController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantSettingController;
use App\Http\Controllers\Api\TenantSubscriptionController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VideoStreamController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', [HealthController::class, '__invoke']);

// Liveness / readiness probes — safe for aggressive polling by
// load balancers and external uptime monitors (no auth, no heavy work).
Route::get('health/live', [HealthController::class, 'live']);
Route::get('health/ready', [HealthController::class, 'ready']);

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

        // ─── تسجيل الدخول عبر Google (معلم/طالب/أدمن) ───
        // الـcallback انتقل إلى routes/web.php ليعمل بجلسة الويب (HttpOnly).
        Route::get('google/redirect', [GoogleAuthController::class, 'redirect']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');
        Route::post('captcha', [SecurityController::class, 'captcha'])->middleware('throttle:captcha');
        Route::post('2fa/confirm', [AuthController::class, 'twoFactorConfirm'])->middleware('throttle:two-factor');
    });

    // ─── Student Mobile App (official app) ───────────────
    Route::prefix('mobile')->group(function () {
        // Public (pre-auth): teacher-code validation + scoped student login.
        // The login endpoint re-validates the code server-side and verifies
        // the student is an active member of that teacher's tenant.
        Route::post('teacher-code/validate', [MobileAppController::class, 'validateCode'])->middleware('throttle:mobile-code');
        Route::prefix('auth')->group(function () {
            Route::post('login', [MobileAuthController::class, 'login'])->middleware('throttle:login');
            Route::post('register', [MobileAuthController::class, 'register'])->middleware('throttle:register');
            Route::post('google', [MobileAuthController::class, 'google'])->middleware('throttle:login');
            Route::post('2fa/confirm', [MobileAuthController::class, 'twoFactorConfirm'])->middleware('throttle:two-factor');
        });

        // Authenticated student endpoints. Teacher context is enforced
        // server-side on every call (never trusted from the client).
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [MobileAuthController::class, 'me']);
            Route::get('teacher/context', [MobileAppController::class, 'context']);
            Route::get('site', [MobileAppController::class, 'site']);
            Route::get('home', [MobileAppController::class, 'home']);
            Route::get('courses', [MobileAppController::class, 'courses']);
            Route::get('courses/{course}', [MobileAppController::class, 'course']);
            Route::get('lessons/{lesson}', [MobileAppController::class, 'lesson']);
            Route::get('announcements', [MobileAppController::class, 'announcements']);
        });
    });

    // ─── Platform subscription billing (Freemius — the ONLY gateway) ──
    // Freemius webhook: public, HMAC-verified (x-signature over raw body).
    // This is the sole authoritative entitlement activation signal.
    Route::post('payments/freemius-webhook', [FreemiusWebhookController::class, 'handle'])->middleware('throttle:webhooks');

    // ─── Booking payments (student ↔ teacher — NOT platform billing) ───
    // These gateways remain ONLY for paid teacher bookings / student
    // purchases. They are intentionally NOT used for platform subscriptions
    // and are never routed through Freemius.
    Route::post('payments/paytabs-callback', [PaymentInitiateController::class, 'paytabsCallback'])->middleware('throttle:webhooks');
    Route::post('payments/paypal-webhook', [PaymentInitiateController::class, 'paypalWebhook'])->middleware('throttle:webhooks');
    Route::get('payments/paypal-capture', [PaymentInitiateController::class, 'paypalCapture'])->middleware('throttle:webhooks');

    Route::middleware('auth:sanctum')->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('2fa/setup', [SecurityController::class, 'twoFactorSetup']);
            Route::post('2fa/enable', [SecurityController::class, 'twoFactorEnable']);
            Route::post('2fa/disable', [SecurityController::class, 'twoFactorDisable']);
            Route::post('email/verify', [AuthController::class, 'verifyEmail']);
            Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:email-verification');
        });

        Route::prefix('user')->group(function () {
            Route::put('profile', [UserController::class, 'updateProfile']);
            Route::put('password', [UserController::class, 'updatePassword']);
        });

        // Protected video playback (auth only — students may not have tenant context)
        Route::get('videos/{video}/playback', [VideoStreamController::class, 'playback'])->middleware('throttle:playback');

        // ─── Student Profile & Gamification (auth only) ──
        Route::prefix('student')->group(function () {
            Route::get('profile', [StudentProfileController::class, 'profile']);
            Route::get('stats', [StudentProfileController::class, 'stats']);
            Route::get('leaderboard', [StudentProfileController::class, 'leaderboard']);
            Route::get('achievements', [StudentProfileController::class, 'achievements']);
            Route::get('points', [StudentProfileController::class, 'points']);
            Route::get('activity', [StudentProfileController::class, 'activity']);
            Route::get('favorites', [StudentProfileController::class, 'favorites']);
            Route::post('favorites/{course}', [StudentProfileController::class, 'toggleFavorite']);
        });

        Route::prefix('tenants')->group(function () {
            Route::get('/', [TenantController::class, 'index']);
            Route::post('/', [TenantController::class, 'store']);
            Route::get('current', [TenantController::class, 'current']);
            Route::post('switch', [TenantController::class, 'switch']);
        });

        Route::middleware(['tenant', 'device', 'subscription'])->group(function () {
            Route::get('audit-logs', [SecurityController::class, 'auditLogs'])->middleware('role:teacher,owner,admin,permission:analytics.view');

            Route::get('dashboard', [DashboardController::class, 'index']);

            // Courses
            Route::get('courses', [CourseController::class, 'index']);
            Route::post('courses', [CourseController::class, 'store']);
            Route::get('courses/{course}', [CourseController::class, 'show']);
            Route::get('courses/{course}/search', [CourseController::class, 'search']);
            Route::put('courses/{course}', [CourseController::class, 'update']);
            Route::delete('courses/{course}', [CourseController::class, 'destroy']);
            Route::post('courses/{course}/publish', [CourseController::class, 'publish']);
            Route::post('courses/{course}/unpublish', [CourseController::class, 'unpublish']);

            // Sections
            Route::get('sections', [SectionController::class, 'index']);
            Route::post('sections', [SectionController::class, 'store']);
            Route::get('sections/{section}', [SectionController::class, 'show']);
            Route::put('sections/{section}', [SectionController::class, 'update']);
            Route::delete('sections/{section}', [SectionController::class, 'destroy']);

            // Lessons
            Route::get('lessons', [LessonController::class, 'index']);
            Route::post('lessons', [LessonController::class, 'store']);
            Route::post('lessons/reorder', [LessonController::class, 'reorder']);
            Route::get('lessons/{lesson}', [LessonController::class, 'show']);
            Route::put('lessons/{lesson}', [LessonController::class, 'update']);
            Route::delete('lessons/{lesson}', [LessonController::class, 'destroy']);

            // Lesson Contents
            Route::get('lessons/{lesson}/contents', [LessonContentController::class, 'index']);
            Route::post('lesson-contents', [LessonContentController::class, 'store']);
            Route::get('lesson-contents/{content}', [LessonContentController::class, 'show']);
            Route::put('lesson-contents/{content}', [LessonContentController::class, 'update']);
            Route::delete('lesson-contents/{content}', [LessonContentController::class, 'destroy']);

            // Enrollments
            Route::get('enrollments', [EnrollmentController::class, 'index']);
            Route::post('enrollments', [EnrollmentController::class, 'store']);
            Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show']);
            Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy']);
            Route::get('my-courses', [EnrollmentController::class, 'myCourses']);

            // Progress
            Route::get('enrollments/{enrollment}/progress', [LessonProgressController::class, 'index']);
            Route::put('enrollments/{enrollment}/progress/{lesson}', [LessonProgressController::class, 'update']);
            Route::post('enrollments/{enrollment}/progress/{lesson}/complete', [LessonProgressController::class, 'markCompleted']);

            // ─── Assignments ──────────────────────────────
            Route::get('assignments', [AssignmentController::class, 'index']);
            Route::post('assignments', [AssignmentController::class, 'store']);
            Route::get('assignments/{assignment}', [AssignmentController::class, 'show']);
            Route::put('assignments/{assignment}', [AssignmentController::class, 'update']);
            Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy']);
            Route::post('assignments/{assignment}/submit', [AssignmentController::class, 'submit']);
            Route::post('assignments/{assignment}/submissions/{submission}/grade', [AssignmentController::class, 'grade']);
            Route::post('submissions/{submission}/comments', [AssignmentController::class, 'comment']);

            // ─── Question Bank ───────────────────────────
            Route::get('questions', [QuestionController::class, 'index']);
            Route::post('questions', [QuestionController::class, 'store']);
            Route::get('questions/{question}', [QuestionController::class, 'show']);
            Route::put('questions/{question}', [QuestionController::class, 'update']);
            Route::delete('questions/{question}', [QuestionController::class, 'destroy']);

            // Question Options
            Route::get('questions/{question}/options', [QuestionOptionController::class, 'index']);
            Route::post('questions/{question}/options', [QuestionOptionController::class, 'store']);
            Route::put('questions/{question}/options/{option}', [QuestionOptionController::class, 'update']);
            Route::delete('questions/{question}/options/{option}', [QuestionOptionController::class, 'destroy']);

            // ─── Exams ───────────────────────────────────
            Route::get('exams', [ExamController::class, 'index']);
            Route::post('exams', [ExamController::class, 'store']);
            Route::get('exams/{exam}', [ExamController::class, 'show']);
            Route::put('exams/{exam}', [ExamController::class, 'update']);
            Route::delete('exams/{exam}', [ExamController::class, 'destroy']);
            Route::post('exams/{exam}/publish', [ExamController::class, 'publish']);
            Route::post('exams/{exam}/add-questions', [ExamController::class, 'addQuestions']);
            Route::delete('exams/{exam}/questions/{question}', [ExamController::class, 'removeQuestion']);

            // Exam Sections
            Route::get('exams/{exam}/sections', [ExamSectionController::class, 'index']);
            Route::post('exams/{exam}/sections', [ExamSectionController::class, 'store']);
            Route::put('exams/{exam}/sections/{section}', [ExamSectionController::class, 'update']);
            Route::delete('exams/{exam}/sections/{section}', [ExamSectionController::class, 'destroy']);

            // ─── Exam Attempts ───────────────────────────
            Route::get('exams/{exam}/attempts', [ExamAttemptController::class, 'index']);
            Route::get('exams/{exam}/questions', [ExamController::class, 'questions']);
            Route::post('exams/{exam}/attempt', [ExamAttemptController::class, 'start']);
            Route::post('exams/{exam}/attempts/{attempt}/submit', [ExamAttemptController::class, 'submit']);
            Route::post('exams/{exam}/attempts/{attempt}/grade', [ExamAttemptController::class, 'grade'])->middleware('role:teacher,owner,admin,permission:exams.manage');
            Route::get('exam-attempts/{attempt}', [ExamAttemptController::class, 'show']);

            // ─── Activation Cards ────────────────────────
            Route::get('cards', [ActivationCardController::class, 'index']);
            Route::get('cards/batches', [ActivationCardController::class, 'batches'])->middleware('role:teacher,owner,admin,permission:cards.manage', 'feature:cards_enabled');
            Route::post('cards/generate', [ActivationCardController::class, 'generate'])->middleware('role:teacher,owner,admin,permission:cards.manage', 'feature:cards_enabled');
            Route::post('cards/import', [ActivationCardController::class, 'import'])->middleware('role:teacher,owner,admin,permission:cards.manage', 'feature:cards_enabled');
            Route::get('cards/import-template', [ActivationCardController::class, 'importTemplate'])->middleware('role:teacher,owner,admin,permission:cards.manage', 'feature:cards_enabled');
            Route::post('cards/redeem', [ActivationCardController::class, 'redeem']);
            Route::get('cards/{card}', [ActivationCardController::class, 'show']);
            Route::post('cards/{card}/cancel', [ActivationCardController::class, 'cancel'])->middleware('role:teacher,owner,admin,permission:cards.manage');
            Route::get('cards/batch/{batchId}', [ActivationCardController::class, 'batchInfo'])->middleware('role:teacher,owner,admin,permission:cards.manage', 'feature:cards_enabled');

            // Payment initiation (subscription checkout — Freemius only)
            Route::post('videos/upload', [FileUploadController::class, 'uploadDirect'])->middleware('throttle:video-upload');
            Route::post('subscriptions/freemius/checkout', [FreemiusCheckoutController::class, 'initiate'])->middleware('throttle:checkout');
            Route::get('subscriptions/freemius/complete', [FreemiusCheckoutController::class, 'complete'])->middleware('throttle:checkout');
            Route::post('subscriptions/freemius/bind', [FreemiusCheckoutController::class, 'bind'])->middleware('throttle:checkout');
            Route::post('subscriptions/freemius/portal', [FreemiusCheckoutController::class, 'portal']);
            Route::post('subscriptions/freemius/cancel', [FreemiusCheckoutController::class, 'cancel']);

            // ─── Support Tickets (teacher/student side) ──
            Route::get('tickets', [AdminController::class, 'myTickets']);
            Route::post('tickets', [AdminController::class, 'ticketStore']);
            Route::get('tickets/{ticket}', [AdminController::class, 'ticketShow']);
            Route::post('tickets/{ticket}/messages', [AdminController::class, 'ticketReply']);

            // ─── Super Admin: Platform Management ────────
            Route::middleware('admin')->prefix('admin')->group(function () {
                Route::get('subscriptions', [AdminController::class, 'subscriptions']);
                Route::post('subscriptions/{subscription}/status', [AdminController::class, 'subscriptionStatus']);
                Route::post('subscriptions/{subscription}/extend', [AdminController::class, 'subscriptionExtend']);
                Route::post('subscriptions/{subscription}/plan', [AdminController::class, 'subscriptionPlan']);

                Route::get('students', [AdminController::class, 'students']);
                Route::post('students', [AdminController::class, 'studentStore']);
                Route::get('students/{student}', [AdminController::class, 'studentShow']);
                Route::put('students/{student}/status', [AdminController::class, 'studentStatus']);
                Route::delete('students/{student}', [AdminController::class, 'studentDestroy']);

                Route::get('tenants/options', [AdminController::class, 'tenantOptions']);

                Route::get('teachers', [AdminController::class, 'teachers']);
                Route::post('teachers', [AdminController::class, 'teacherStore']);
                Route::get('teachers/{teacher}', [AdminController::class, 'teacherShow']);
                Route::put('teachers/{teacher}/status', [AdminController::class, 'teacherStatus']);
                Route::delete('teachers/{teacher}', [AdminController::class, 'teacherDestroy']);

                Route::get('payments', [AdminController::class, 'payments']);
                Route::get('payments/{payment}', [AdminController::class, 'paymentShow']);
                Route::get('payments/{payment}/proof', [AdminController::class, 'paymentProof']);
                Route::put('payments/{payment}/status', [AdminController::class, 'paymentStatus']);
                // Booking gateways connection test (PayTabs / PayPal only).
                Route::post('payment-gateway-test', [PaymentInitiateController::class, 'testConnection']);

                Route::get('coupons', [AdminController::class, 'coupons']);
                Route::post('coupons', [AdminController::class, 'couponStore']);
                Route::put('coupons/{coupon}', [AdminController::class, 'couponUpdate']);
                Route::post('coupons/{coupon}/toggle', [AdminController::class, 'couponToggle']);
                Route::delete('coupons/{coupon}', [AdminController::class, 'couponDestroy']);

                Route::get('tickets', [AdminController::class, 'tickets']);
                Route::get('tickets/{ticket}', [AdminController::class, 'ticketShow']);
                Route::post('tickets', [AdminController::class, 'ticketStore']);
                Route::post('tickets/{ticket}/messages', [AdminController::class, 'ticketReply']);
                Route::put('tickets/{ticket}/status', [AdminController::class, 'ticketStatus']);
                Route::put('tickets/{ticket}/assign', [AdminController::class, 'ticketAssign']);

                Route::get('error-logs', [AdminController::class, 'errorLogs']);
                Route::delete('error-logs/{log}', [AdminController::class, 'errorLogDestroy']);
                Route::post('error-logs/clear', [AdminController::class, 'errorLogsClear']);

                // ─── Quran Teacher Free Access Requests ────────
                Route::get('quran-teacher-requests', [QuranTeacherRequestController::class, 'index']);
                Route::get('quran-teacher-requests/stats', [QuranTeacherRequestController::class, 'stats']);
                Route::put('quran-teacher-requests/{request}', [QuranTeacherRequestController::class, 'update']);
            });

            // ─── Subscription Plans + Features (Super Admin only) ────────
            Route::middleware('admin')->group(function () {
                Route::get('admin/feature-catalog', [SubscriptionPlanController::class, 'catalog']);
                Route::get('admin/plans', [SubscriptionPlanController::class, 'index']);
                Route::post('admin/plans', [SubscriptionPlanController::class, 'store']);
                Route::get('admin/plans/{plan}', [SubscriptionPlanController::class, 'show']);
                Route::put('admin/plans/{plan}', [SubscriptionPlanController::class, 'update']);
                Route::delete('admin/plans/{plan}', [SubscriptionPlanController::class, 'destroy']);

                Route::get('admin/plans/{plan}/features', [PlanFeatureController::class, 'index']);
                Route::post('admin/plans/{plan}/features', [PlanFeatureController::class, 'store']);
                Route::put('admin/plans/{plan}/features/{feature}', [PlanFeatureController::class, 'update']);
                Route::delete('admin/plans/{plan}/features/{feature}', [PlanFeatureController::class, 'destroy']);
            });

            // ─── Media Infrastructure (Super Admin) ────────
            Route::middleware('admin')->prefix('admin/bi')->group(function () {
                Route::get('/', [BiController::class, 'index']);
                Route::post('recalculate', [BiController::class, 'recalculate']);
                Route::get('cost-rules', [BiController::class, 'costRules']);
                Route::put('cost-rules/{id}', [BiController::class, 'updateCostRule']);
                Route::get('plans/{plan}', [BiController::class, 'planCheck']);
            });

            Route::middleware('admin')->prefix('admin/media')->group(function () {
                Route::get('/', [MediaAdminController::class, 'dashboard']);
                Route::put('providers/{provider}', [MediaAdminController::class, 'updateProvider']);
                Route::post('providers/{provider}/test', [MediaAdminController::class, 'testProvider']);
                Route::post('providers/{provider}/sync', [MediaAdminController::class, 'sync']);
                Route::get('profitability', [MediaAdminController::class, 'profitability']);
                Route::get('pricing', [MediaAdminController::class, 'pricing']);
                Route::post('pricing', [MediaAdminController::class, 'updatePricing']);
                Route::get('webhook-events', [MediaAdminController::class, 'webhookEvents']);
            });

            // ─── Admin: Live (Google Meet only — status) ───
            Route::middleware('admin')->prefix('admin/live')->group(function () {
                Route::get('providers', [AdminLiveProviderController::class, 'index']);
            });

            // ─── Teacher: Media Engine ─────────────────────
            Route::middleware('role:teacher,owner,admin,permission:media.manage')->prefix('teacher/media')->group(function () {
                Route::get('/', [MediaAssetController::class, 'index']);
                Route::get('usage', [MediaAssetController::class, 'usage']);
                Route::post('upload-ticket', [MediaAssetController::class, 'uploadTicket']);
                Route::delete('assets/{asset}', [MediaAssetController::class, 'destroy']);
            });

            // ─── Teacher: Video security (watermark) ───────
            Route::middleware('role:teacher,owner,admin,permission:media.manage')->prefix('teacher/video-security')->group(function () {
                Route::get('/', [TeacherVideoSecurityController::class, 'show']);
                Route::put('/', [TeacherVideoSecurityController::class, 'update']);
            });

            // ─── Media playback (authenticated) ────────────
            Route::get('media/assets/{asset}/playback', [MediaAssetController::class, 'playback']);

            // Plan Features (moved above into the Super Admin group)

            // ─── Public Plans (any authenticated user) ────
            Route::get('plans', [SubscriptionPlanController::class, 'index']);
            Route::get('plans/{plan}', [SubscriptionPlanController::class, 'show']);

            // ─── Tenant Subscriptions ─────────────────────
            Route::get('subscriptions', [TenantSubscriptionController::class, 'index']);
            Route::get('subscriptions/current', [TenantSubscriptionController::class, 'current']);
            Route::post('subscriptions', [TenantSubscriptionController::class, 'subscribe']);
            Route::get('subscriptions/{subscription}', [TenantSubscriptionController::class, 'show']);
            Route::post('subscriptions/{subscription}/cancel', [TenantSubscriptionController::class, 'cancel']);

            // ─── Payments ─────────────────────────────────
            Route::get('payments', [PaymentController::class, 'index']);
            Route::get('payments/{payment}', [PaymentController::class, 'show']);
            // Payment creation and status changes happen exclusively through
            // the gateway-initiate flow and the super-admin /admin endpoints.
            // The legacy fake-gateway create/update routes were removed in
            // the production-hardening pass.

            // ─── Invoices ─────────────────────────────────
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);

            // ─── Transactions ─────────────────────────────
            Route::get('transactions', [TransactionController::class, 'index']);
            Route::get('transactions/{transaction}', [TransactionController::class, 'show']);

            // ─── Tenant Settings ──────────────────────────
            Route::get('settings', [TenantSettingController::class, 'show']);
            Route::put('settings', [TenantSettingController::class, 'update']);

            // ─── Notifications ───────────────────────────
            Route::prefix('notifications')->group(function () {
                Route::get('/', [NotificationController::class, 'index']);
                Route::post('{id}/read', [NotificationController::class, 'markAsRead']);
                Route::post('read-all', [NotificationController::class, 'markAllAsRead']);
                Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            });

            // ─── Notification Preferences ────────────────
            Route::prefix('preferences')->group(function () {
                Route::get('/', [NotificationPreferenceController::class, 'index']);
                Route::put('/', [NotificationPreferenceController::class, 'update']);
            });

            // ─── Push Tokens ─────────────────────────────
            Route::prefix('push-tokens')->group(function () {
                Route::post('/', [PushTokenController::class, 'store']);
                Route::delete('/', [PushTokenController::class, 'destroy']);
            });

            // ─── Announcements ───────────────────────────
            Route::prefix('announcements')->group(function () {
                Route::get('/', [AnnouncementController::class, 'index']);
                Route::post('/', [AnnouncementController::class, 'store'])->middleware('role:teacher,owner,admin,permission:announcements.manage', 'feature:announcements_enabled');
                Route::get('{announcement}', [AnnouncementController::class, 'show']);
                Route::put('{announcement}', [AnnouncementController::class, 'update'])->middleware('role:teacher,owner,admin,permission:announcements.manage', 'feature:announcements_enabled');
                Route::delete('{announcement}', [AnnouncementController::class, 'destroy'])->middleware('role:teacher,owner,admin,permission:announcements.manage', 'feature:announcements_enabled');
                Route::post('{announcement}/send', [AnnouncementController::class, 'send'])->middleware('role:teacher,owner,admin,permission:announcements.manage', 'feature:announcements_enabled', 'throttle:announcements');
            });

            // ─── Conversations / Chat ────────────────────
            Route::prefix('conversations')->group(function () {
                Route::get('unread-total', [ConversationMessageController::class, 'unreadTotals']);
                Route::get('contacts', [ConversationController::class, 'contacts']);
                Route::get('/', [ConversationController::class, 'index']);
                Route::post('/', [ConversationController::class, 'store']);
                Route::get('{conversation}', [ConversationController::class, 'show']);
                Route::get('{conversation}/messages', [ConversationMessageController::class, 'index']);
                Route::post('{conversation}/messages', [ConversationMessageController::class, 'store']);
                Route::post('{conversation}/read', [ConversationMessageController::class, 'read']);
            });

            // ─── Analytics ───────────────────────────────
            Route::prefix('analytics')->group(function () {
                Route::get('/', [AnalyticsController::class, 'index']);
                Route::get('daily/{statType}', [AnalyticsController::class, 'daily']);
                Route::post('clear-cache', [AnalyticsController::class, 'clearCache'])->middleware('admin');
            });

            // ─── Search ──────────────────────────────────
            Route::prefix('search')->group(function () {
                Route::get('/', SearchController::class);
                Route::get('courses', [SearchController::class, 'courses']);
                Route::get('lessons', [SearchController::class, 'lessons']);
                Route::get('students', [SearchController::class, 'students'])->middleware('role:teacher,owner,admin,permission:students.manage');
            });

            // ─── Community: Overview ────────────────────
            Route::get('community/overview', [CommunityController::class, 'overview']);

            // ─── Community: Groups ───────────────────────
            Route::middleware('feature:community_enabled')->prefix('community/groups')->group(function () {
                Route::get('/', [CommunityGroupController::class, 'index']);
                Route::post('/', [CommunityGroupController::class, 'store'])->middleware('role:teacher,owner,admin,permission:community.manage');
                Route::get('{group}', [CommunityGroupController::class, 'show']);
                Route::put('{group}', [CommunityGroupController::class, 'update'])->middleware('role:teacher,owner,admin,permission:community.manage');
                Route::delete('{group}', [CommunityGroupController::class, 'destroy'])->middleware('role:teacher,owner,admin,permission:community.manage');
                Route::post('{group}/join', [CommunityGroupController::class, 'join']);
                Route::post('{group}/leave', [CommunityGroupController::class, 'leave']);
                Route::get('{group}/members', [CommunityGroupController::class, 'members']);
                Route::put('{group}/members/{member}', [CommunityGroupController::class, 'updateMember'])->middleware('role:teacher,owner,admin,permission:community.manage');
                Route::delete('{group}/members/{member}', [CommunityGroupController::class, 'removeMember'])->middleware('role:teacher,owner,admin,permission:community.manage');
            });

            // ─── Community: Threads (forum + Q&A) ────────
            Route::middleware('feature:community_enabled')->prefix('community/threads')->group(function () {
                Route::get('/', [CommunityThreadController::class, 'index']);
                Route::post('/', [CommunityThreadController::class, 'store']);
                Route::get('{thread}', [CommunityThreadController::class, 'show']);
                Route::put('{thread}', [CommunityThreadController::class, 'update']);
                Route::delete('{thread}', [CommunityThreadController::class, 'destroy']);
                Route::post('{thread}/pin', [CommunityThreadController::class, 'pin'])->middleware('role:teacher,owner,admin,permission:community.manage');
                Route::post('{thread}/status', [CommunityThreadController::class, 'toggleStatus'])->middleware('role:teacher,owner,admin,permission:community.manage');
                Route::post('{thread}/like', [CommunityLikeController::class, 'toggleThread']);
            });

            // ─── Community: Comments & Answers ───────────
            Route::middleware('feature:community_enabled')->prefix('community/threads/{thread}/comments')->group(function () {
                Route::post('/', [CommunityCommentController::class, 'store']);
                Route::put('{comment}', [CommunityCommentController::class, 'update']);
                Route::delete('{comment}', [CommunityCommentController::class, 'destroy']);
                Route::post('{comment}/answer', [CommunityCommentController::class, 'answer']);
                Route::post('{comment}/like', [CommunityLikeController::class, 'toggleComment']);
            });

            // ─── Teacher: Live Sessions ──────────────────
            Route::middleware('role:teacher,owner,admin,permission:live.manage', 'feature:live_stream_enabled')->prefix('teacher/live-sessions')->group(function () {
                Route::get('providers', [LiveSessionController::class, 'providers']);
                Route::get('/', [LiveSessionController::class, 'index']);
                Route::post('/', [LiveSessionController::class, 'store'])->middleware('throttle:live-create');
                Route::get('{session}', [LiveSessionController::class, 'show']);
                Route::put('{session}', [LiveSessionController::class, 'update']);
                Route::post('{session}/start', [LiveSessionController::class, 'start']);
                Route::post('{session}/end', [LiveSessionController::class, 'end']);
                Route::post('{session}/cancel', [LiveSessionController::class, 'cancel']);
                Route::get('by-lesson/{lesson}', [LiveSessionController::class, 'byLesson']);
                Route::post('{session}/join-ticket', [LiveSessionController::class, 'joinTicket'])->middleware('throttle:join-ticket');
                Route::delete('{session}', [LiveSessionController::class, 'destroy']);
            });

            // ─── Public: Live Session join page data ─────
            Route::post('live-sessions/{session}/join-ticket', [LiveSessionController::class, 'joinTicket'])->middleware('throttle:join-ticket');
            Route::get('live-sessions/{session}', [LiveSessionController::class, 'show']);

            // ─── Teacher: Scheduled Lessons ────────────────
            Route::middleware('role:teacher,owner,admin,permission:schedule.manage', 'feature:scheduled_lessons_enabled')->prefix('teacher/scheduled-lessons')->group(function () {
                Route::get('/', [ScheduledLessonController::class, 'index']);
                Route::post('/', [ScheduledLessonController::class, 'store']);
                Route::get('{lesson}', [ScheduledLessonController::class, 'show']);
                Route::put('{lesson}', [ScheduledLessonController::class, 'update']);
                Route::post('{lesson}/complete', [ScheduledLessonController::class, 'complete']);
                Route::post('{lesson}/cancel', [ScheduledLessonController::class, 'cancel']);
                Route::delete('{lesson}', [ScheduledLessonController::class, 'destroy']);
            });

            // ─── Student: Upcoming scheduled lessons ──────
            Route::get('scheduled-lessons', [ScheduledLessonController::class, 'upcoming']);

            // ─── Student ─────────────────────────────────
            Route::get('student/exam-attempts', [StudentAttemptController::class, 'index']);
            Route::get('student/certificates', [StudentCertificateController::class, 'index']);
            Route::get('student/certificates/{certificate}', [StudentCertificateController::class, 'show']);

            // ─── File download ticket (signed, short-lived) ──
            Route::get('lessons/{lesson}/file-ticket', [FileDownloadController::class, 'ticket']);

            // ─── Teacher: Blog ─────────────────────────────
            Route::middleware('feature:blog_enabled', 'role:teacher,owner,admin,permission:blog.manage')->prefix('teacher/blog')->group(function () {
                Route::get('/', [TeacherBlogController::class, 'index']);
                Route::post('/', [TeacherBlogController::class, 'store']);
                Route::put('{post}', [TeacherBlogController::class, 'update']);
                Route::delete('{post}', [TeacherBlogController::class, 'destroy']);
                Route::post('{post}/publish', [TeacherBlogController::class, 'publish']);
                Route::post('{post}/unpublish', [TeacherBlogController::class, 'unpublish']);
            });

            // ─── Teacher: Custom Domain ────────────────────
            Route::middleware('role:teacher,owner,admin,permission:settings.manage')->prefix('teacher/domain')->group(function () {
                Route::get('/', [TeacherDomainController::class, 'show']);
                Route::put('/', [TeacherDomainController::class, 'connect']);
                Route::post('verify', [TeacherDomainController::class, 'verify']);
                Route::delete('/', [TeacherDomainController::class, 'disconnect']);
            });

            // ─── Teacher: Ask Me (اسألني) ──────────────────
            Route::middleware('role:teacher,owner,admin')->prefix('teacher/assistant')->group(function () {
                Route::post('ask', [AssistantController::class, 'ask'])->middleware('throttle:assistant-ask');
            });

            // ─── Teacher: Plan & Limits ───────────────────
            Route::get('teacher/plan', [TeacherPlanController::class, 'index']);

            // ─── Teacher: Site Design ─────────────────────
            Route::middleware('role:teacher,owner,admin,permission:settings.manage')->prefix('teacher/site-design')->group(function () {
                Route::get('/', [TeacherSiteController::class, 'show']);
                Route::put('/', [TeacherSiteController::class, 'update']);
            });

            // ─── Teacher: Student Mobile App ─────────────
            Route::middleware('role:teacher,owner,admin,permission:settings.manage')->prefix('teacher/student-app')->group(function () {
                Route::get('/', [TeacherStudentAppController::class, 'show']);
                Route::post('regenerate', [TeacherStudentAppController::class, 'regenerate']);
            });

            // ─── Teacher: Analytics ──────────────────────
            Route::middleware('role:teacher,owner,admin,permission:analytics.view')->prefix('teacher/analytics')->group(function () {
                Route::get('/', [TeacherAnalyticsController::class, 'index']);
                Route::get('report/pdf', [TeacherAnalyticsController::class, 'reportPdf']);
                Route::get('report/excel', [TeacherAnalyticsController::class, 'reportExcel']);
            });

            // ─── Teacher: Device Control ────────────────
            Route::middleware('role:teacher,owner,admin,permission:students.manage')->prefix('teacher')->group(function () {
                Route::get('device-policy', [TeacherDeviceController::class, 'policy']);
                Route::put('device-policy', [TeacherDeviceController::class, 'updatePolicy']);
                Route::get('students', [TeacherDeviceController::class, 'students']);
                Route::get('students/{student}', [TeacherDeviceController::class, 'showStudent']);
                Route::get('students/{student}/activity', [TeacherDeviceController::class, 'studentActivity']);
                Route::post('students/{student}/device-limit', [TeacherDeviceController::class, 'setStudentLimit']);
                Route::delete('students/{student}/device-limit', [TeacherDeviceController::class, 'removeStudentLimit']);
                Route::post('students/{student}/reset-devices', [TeacherDeviceController::class, 'resetDevices']);
                Route::get('file-policy', [TeacherFilePolicyController::class, 'policy']);
                Route::put('file-policy', [TeacherFilePolicyController::class, 'updatePolicy']);
            });

            // ─── Teacher: Availability (appointment booking) ──
            Route::middleware('feature:appointments_enabled', 'role:teacher,owner,admin,permission:settings.manage')->prefix('teacher/availabilities')->group(function () {
                Route::get('/', [TeacherAvailabilityController::class, 'index']);
                Route::post('/', [TeacherAvailabilityController::class, 'store']);
                Route::put('{availability}', [TeacherAvailabilityController::class, 'update']);
                Route::delete('{availability}', [TeacherAvailabilityController::class, 'destroy']);
            });

            // ─── Teacher: Paid services (consultations + sessions) ──
            Route::middleware('feature:paid_services_enabled', 'role:teacher,owner,admin,permission:settings.manage')->prefix('teacher/services')->group(function () {
                Route::get('/', [TeacherServiceController::class, 'index']);
                Route::post('/', [TeacherServiceController::class, 'store']);
                Route::get('{service}', [TeacherServiceController::class, 'show']);
                Route::put('{service}', [TeacherServiceController::class, 'update']);
                Route::delete('{service}', [TeacherServiceController::class, 'destroy']);
            });

            // ─── Teacher: Bookings management ────────────
            Route::middleware('role:teacher,owner,admin,permission:settings.manage')->prefix('teacher/bookings')->group(function () {
                Route::get('/', [TeacherBookingController::class, 'index']);
                Route::get('{booking}', [TeacherBookingController::class, 'show']);
                Route::put('{booking}/status', [TeacherBookingController::class, 'updateStatus']);
            });

            // ─── Teacher: Staff / multi-teacher ──────────
            Route::prefix('teacher/staff')->group(function () {
                Route::get('/', [TeacherStaffController::class, 'index']);
                Route::post('invitations', [TeacherStaffController::class, 'invite']);
                Route::delete('invitations/{invitation}', [TeacherStaffController::class, 'cancelInvitation']);
                Route::put('members/{member}', [TeacherStaffController::class, 'updateMember']);
                Route::delete('members/{member}', [TeacherStaffController::class, 'removeMember']);
            });
        });
    });

    // ─── Super Admin: Backups ─────────────────────────
    // Platform-wide backup center. Super admin only (admin middleware +
    // BackupPolicy) — no tenant/device/subscription context is required.
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin/backups')->group(function () {
        Route::get('/', [BackupController::class, 'index']);
        Route::post('/', [BackupController::class, 'store'])->middleware('throttle:backups');
        Route::get('settings', [BackupController::class, 'settings']);
        Route::put('settings', [BackupController::class, 'updateSettings']);
        Route::get('{backup}', [BackupController::class, 'show']);
        Route::post('{backup}/verify', [BackupController::class, 'verify']);
        Route::get('{backup}/download/{artifact?}', [BackupController::class, 'download'])->where('artifact', '[\w.\-]+');
        Route::delete('{backup}', [BackupController::class, 'destroy']);
    });

    // Signed (HMAC) download links work without a bearer token — the signature
    // itself is the authorization. Registered outside the admin group.
    Route::prefix('admin/backups')->group(function () {
        Route::get('{backupId}/signed-download', [BackupController::class, 'signedDownload']);
    });

    // ─── Public: Plan Recommender Assistant ────────
    // متاح للزوار (قبل التسجيل) وللمعلمين معاً؛ إن وُجد توكن مع X-Tenant-Id
    // يتم وضع علامة "خطتك الحالية" على الخطة المقترحة تلقائياً.
    Route::prefix('teacher/plan-recommender')->group(function () {
        Route::get('questions', [PlanRecommenderController::class, 'questions']);
        Route::post('recommend', [PlanRecommenderController::class, 'recommend'])->middleware('throttle:recommend');
    });

    // ─── Public Site ────────────────────────────────
    Route::prefix('public')->group(function () {
        Route::get('plans', [PublicSiteController::class, 'plans']);
        Route::get('sitemap', [PublicSiteController::class, 'sitemap'])->middleware('throttle:60,1');
        Route::get('cards/{code}', [PublicSiteController::class, 'cardInfo']);
        Route::post('contact', [PublicSiteController::class, 'contactStore'])->middleware('throttle:contact');
        Route::get('certificates/{number}', [StudentCertificateController::class, 'verify']);
        Route::get('courses/{course}/sections', [PublicSiteController::class, 'sections']);
        Route::get('courses/{course}/lessons/{lesson}', [PublicSiteController::class, 'lesson']);
        Route::get('{slug}', [PublicSiteController::class, 'show']);
        Route::get('{slug}/courses', [PublicSiteController::class, 'courses']);
        Route::get('{slug}/posts', [TeacherBlogController::class, 'publicPosts']);
        Route::get('{slug}/posts/{post}', [TeacherBlogController::class, 'publicPost']);
    });

    // ─── Public: Booking & paid services (teacher site) ──
    Route::prefix('public/{slug}/book')->group(function () {
        Route::get('teachers', [PublicBookingController::class, 'teachers']);
        Route::get('services', [PublicBookingController::class, 'services']);
        Route::get('slots', [PublicBookingController::class, 'slots']);
        Route::post('/', [PublicBookingController::class, 'store'])->middleware('auth:sanctum', 'throttle:bookings');
    });

    // ─── Student: my bookings ───────────────────────
    Route::get('my-bookings', [PublicBookingController::class, 'myBookings'])->middleware('auth:sanctum');
    Route::post('my-bookings/{booking}/cancel', [PublicBookingController::class, 'cancel'])->middleware('auth:sanctum');

    // ─── Teacher invitation acceptance ──────────────
    Route::post('teacher/staff/invitations/{token}/accept', [TeacherStaffController::class, 'acceptInvitation'])->middleware('auth:sanctum');

    // ─── Payment Gateways (Freemius status for the checkout UI) ──────
    Route::get('payment-gateways', [FreemiusCheckoutController::class, 'gateways']);

    // ─── Integrations: OAuth callback & Zapier webhook (public) ──

    // NOTE: the legacy refund webhook (refund-webhook / REFUND_WEBHOOK_SECRET)
    // was removed with the Freemius cutover. Platform-subscription refunds are
    // now issued and reconciled exclusively through Freemius. The
    // RefundSyncService remains available for reconciling any historic
    // (pre-cutover) refunds during the migration window if required.

    // ─── Platform Settings ─────────────────────────
    Route::get('platform-settings', [PlatformSettingController::class, 'show']);
    Route::get('public/domain/{domain}', [TeacherDomainController::class, 'lookup']);

    // ─── Quran Teacher Free Access Request (public) ──────────────────
    Route::post('quran-teacher-requests', [QuranTeacherRequestController::class, 'store']);

    Route::put('platform-settings', [PlatformSettingController::class, 'update'])->middleware('auth:sanctum', 'admin');
    Route::post('upload', [FileUploadController::class, 'upload'])->middleware('auth:sanctum', 'throttle:uploads');
    Route::post('videos/upload-ticket', [FileUploadController::class, 'uploadTicket'])->middleware('auth:sanctum', 'throttle:video-upload');
    Route::post('assignment-upload', [FileUploadController::class, 'assignmentUpload'])->middleware('auth:sanctum', 'throttle:uploads');

    // ─── Protected Video Streaming (HMAC-signed token in URL, no header needed) ──
    Route::get('stream/{video}', [VideoStreamController::class, 'serve'])->middleware('throttle:stream');

    // ─── Media Engine webhook (Bunny provider events, HMAC-signed) ──────────
    Route::post('media/webhook/{provider}', [MediaWebhookController::class, 'handle'])->middleware('throttle:webhooks');

    // ─── Protected File Streaming (enrollment check + download policy) ──────
    Route::get('lessons/{lesson}/file', [FileDownloadController::class, 'download']);

    // ─── Monitoring (super-admin only, rate-limited) ───────────────────────
    Route::middleware(['auth:sanctum', 'admin', 'throttle:monitoring'])->prefix('admin/monitoring')->group(function () {
        Route::get('summary', [MonitoringController::class, 'summary']);
        Route::get('snapshots', [MonitoringController::class, 'snapshots']);
        Route::get('alerts', [MonitoringController::class, 'alerts']);
        Route::post('alerts/{alert}/resolve', [MonitoringController::class, 'resolveAlert']);
        Route::get('slow-requests', [MonitoringController::class, 'slowRequests']);
        Route::get('frontend-errors', [MonitoringController::class, 'frontendErrors']);
    });

    // ─── Client-side error ingestion (public, heavily rate-limited) ───────
    Route::post('monitoring/ingest', MonitoringIngestController::class)->middleware('throttle:monitoring-ingest');
});
